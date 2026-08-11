const { Client, GatewayIntentBits, REST, Routes, SlashCommandBuilder } = require('discord.js');
const net = require('net');
const crypto = require('crypto');
const http = require('http');
const https = require('https');

const CMS_CONFIG_URL = process.env.DAOC_CMS_CONFIG_URL || '';
const BOOTSTRAP_SECRET = process.env.DAOC_CMS_BOOTSTRAP_SECRET || '';
const WEBHOOK_URL = process.env.DAOC_CMS_WEBHOOK_URL || '';

function validateEnvironment() {
    const missing = [];
    if (!CMS_CONFIG_URL) missing.push('DAOC_CMS_CONFIG_URL');
    if (!BOOTSTRAP_SECRET) missing.push('DAOC_CMS_BOOTSTRAP_SECRET');
    if (!WEBHOOK_URL) missing.push('DAOC_CMS_WEBHOOK_URL');

    if (missing.length > 0) {
        throw new Error(`Missing required environment variables: ${missing.join(', ')}`);
    }
}

async function fetchCmsConfig() {
    return new Promise((resolve, reject) => {
        const url = new URL(CMS_CONFIG_URL);
        const lib = url.protocol === 'https:' ? https : http;

        lib.get(url, {
            headers: { 'X-DAOC-CMS-Bootstrap': BOOTSTRAP_SECRET }
        }, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                try {
                    const parsed = JSON.parse(data);
                    if (parsed.ok) resolve(parsed.config);
                    else reject(new Error('Unauthorized'));
                } catch (e) {
                    reject(e);
                }
            });
        }).on('error', reject);
    });
}

async function initBot() {
    try {
        validateEnvironment();
        const config = await fetchCmsConfig();

        if (!config.is_active) {
            process.exit(0);
        }

        const socketSecret = config.socket_secret;

        if (!socketSecret) {
            console.error('CRITICAL BOT ERROR: socket_secret is missing from the CMS configuration.');
            process.exit(1);
        }

        const client = new Client({
            intents: [
                GatewayIntentBits.Guilds,
                GatewayIntentBits.GuildMessages,
                GatewayIntentBits.MessageContent
            ]
        });

        client.once('ready', async () => {
            const commands = [
                new SlashCommandBuilder().setName('status').setDescription('Shows server status and the online player count'),
                new SlashCommandBuilder().setName('players').setDescription('Lists currently logged-in players'),
                new SlashCommandBuilder().setName('reboot').setDescription('Restarts the game server'),
                new SlashCommandBuilder().setName('broadcast').setDescription('Sends a global message').addStringOption(o => o.setName('message').setDescription('Message').setRequired(true)),
                new SlashCommandBuilder().setName('char').setDescription('Shows character information').addStringOption(o => o.setName('name').setDescription('Character name').setRequired(true)),
                new SlashCommandBuilder().setName('guild').setDescription('Shows guild information').addStringOption(o => o.setName('name').setDescription('Guild name').setRequired(true)),
                new SlashCommandBuilder().setName('leaderboard').setDescription('Shows the current ranking').addStringOption(o => o.setName('type').setDescription('Type').addChoices({ name: 'Realm Points', value: 'realm_points' }, { name: 'Level', value: 'level' }, { name: 'Kills', value: 'kills' })),
                new SlashCommandBuilder().setName('aisk').setDescription('Asks the AI a question through Discord').addStringOption(o => o.setName('question').setDescription('Question').setRequired(true)),
                new SlashCommandBuilder().setName('createguildchannel').setDescription('Creates a private channel for your in-game guild').addStringOption(o => o.setName('guildname').setDescription('Guild name').setRequired(false))
            ].map(c => c.toJSON());

            const rest = new REST({ version: '10' }).setToken(config.discord_token);
            try {
                await rest.put(Routes.applicationCommands(client.user.id), { body: commands });
                
                for (const [guildId] of client.guilds.cache) {
                    await rest.put(Routes.applicationGuildCommands(client.user.id, guildId), { body: commands });
                }
                console.log('Slash commands registered globally and for each guild.');
            } catch (err) {
                console.error('Command registration failed:', err);
            }

            startSocketServer(config.socket_port, socketSecret, client);
        });

        const COMMAND_ACTION_MAP = {
            char: 'char_lookup',
            guild: 'guild_lookup',
            aisk: 'ai_ask',
            createguildchannel: 'create_guild_channel'
        };

        client.on('interactionCreate', async interaction => {
            if (!interaction.isChatInputCommand()) return;

            await interaction.deferReply();

            const payload = {
                action: COMMAND_ACTION_MAP[interaction.commandName] || interaction.commandName,
                meta: { timestamp: Math.floor(Date.now() / 1000) },
                params: {}
            };

            interaction.options.data.forEach(opt => {
                payload.params[opt.name] = opt.value;
            });
            payload.params.discord_user = interaction.user.tag;
            payload.params.discord_id = interaction.user.id;

            payload.signature = crypto.createHmac('sha256', socketSecret)
                .update(payload.action + payload.meta.timestamp)
                .digest('hex');

            const rawPayload = JSON.stringify(payload);
            const headerSignature = 'sha256=' + crypto.createHmac('sha256', socketSecret)
                .update(rawPayload)
                .digest('hex');

            sendWebhookAndReply(rawPayload, headerSignature, interaction);
        });

        client.on('messageCreate', async (message) => {
            if (message.author.bot || !message.guild) return;

            const payload = {
                action: 'guild_chat',
                meta: { timestamp: Math.floor(Date.now() / 1000) },
                params: {
                    discord_user: message.author.tag,
                    discord_id: message.author.id,
                    channel_id: message.channel.id,
                    message: message.content
                }
            };

            payload.signature = crypto.createHmac('sha256', socketSecret)
                .update(payload.action + payload.meta.timestamp)
                .digest('hex');

            const rawPayload = JSON.stringify(payload);
            const headerSignature = 'sha256=' + crypto.createHmac('sha256', socketSecret)
                .update(rawPayload)
                .digest('hex');

            sendWebhook(rawPayload, headerSignature);
        });

        await client.login(config.discord_token);

    } catch (err) {
        console.error('CRITICAL BOT ERROR:', err);
        process.exit(1);
    }
}

function sendWebhook(rawPayload, signature) {
    const url = new URL(WEBHOOK_URL);
    const lib = url.protocol === 'https:' ? https : http;
    let responseData = '';
    const req = lib.request({
        hostname: url.hostname,
        port: url.port || (url.protocol === 'https:' ? 443 : 80),
        path: url.pathname,
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Webhook-Signature': signature,
            'Content-Length': Buffer.byteLength(rawPayload)
        }
    }, (res) => {
        res.on('data', chunk => responseData += chunk);
        res.on('end', () => {
            console.log(`[guild_chat webhook] HTTP ${res.statusCode} | Response: ${responseData}`);
        });
    });
    req.on('error', (err) => {
        console.error('[guild_chat webhook] Request failed:', err.message);
    });
    req.write(rawPayload);
    req.end();
}

function sendWebhookAndReply(rawPayload, signature, interaction) {
    const url = new URL(WEBHOOK_URL);
    const lib = url.protocol === 'https:' ? https : http;
    let responseData = '';

    const req = lib.request({
        hostname: url.hostname,
        port: url.port || (url.protocol === 'https:' ? 443 : 80),
        path: url.pathname,
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Webhook-Signature': signature,
            'Content-Length': Buffer.byteLength(rawPayload)
        }
    }, (res) => {
        res.on('data', chunk => responseData += chunk);
        res.on('end', async () => {
            try {
                const parsed = JSON.parse(responseData);
                if (parsed.status === 'ok') {
                    if (interaction.commandName === 'status') {
                        const resObj = parsed.result;
                        await interaction.editReply(`Server Online: ${resObj.server_online ? 'Yes' : 'No'} | Players: ${resObj.players_online}`);
                    } else if (interaction.commandName === 'players' || interaction.commandName === 'player') {
                        const list = parsed.result.players ? parsed.result.players.map(p => `${p.name} (Lvl ${p.level})`).join(', ') : 'No players online';
                        await interaction.editReply(`Online Players: ${list}`);
                    } else if (interaction.commandName === 'char') {
                        const c = parsed.result;
                        await interaction.editReply(`Character: ${c.name} | Level: ${c.level} | Class: ${c.class_id} | Realm Points: ${c.realm_points}`);
                    } else if (interaction.commandName === 'guild') {
                        const g = parsed.result;
                        await interaction.editReply(`Guild: ${g.name} | Realm: ${g.realm} | Members: ${g.member_count}`);
                    } else if (interaction.commandName === 'aisk') {
                        await interaction.editReply(parsed.result.answer);
                    } else if (interaction.commandName === 'leaderboard') {
                        const entries = parsed.result.entries.map((e, i) => `${i+1}. ${e.name} - ${e.score}`).join('\n') || 'No entries';
                        await interaction.editReply(`Leaderboard (${parsed.result.type}):\n${entries}`);
                    } else if (interaction.commandName === 'createguildchannel') {
                        await interaction.editReply(parsed.result || parsed.message || 'Guild channel created.');
                    } else {
                        await interaction.editReply(parsed.message || 'Command executed successfully.');
                    }
                } else {
                    await interaction.editReply(parsed.message || 'CMS returned error status.');
                }
            } catch (e) {
                console.error('CMS Raw Response:', responseData);
                await interaction.editReply(`CMS error (invalid JSON): \`\`\`${responseData.slice(0, 1500)}\`\`\``);
            }
        });
    });

    req.on('error', async () => {
        await interaction.editReply('Failed to reach CMS webhook.');
    });

    req.write(rawPayload);
    req.end();
}

function startSocketServer(port, secret, client) {
    const server = net.createServer((socket) => {
        socket.on('data', async (data) => {
            try {
                const packet = JSON.parse(data.toString());
                console.log(`[socket in] action=${packet.action || packet.event} raw=${data.toString().trim()}`);

                const action    = packet.action || packet.event || '';
                const timestamp = packet.meta && packet.meta.timestamp;
                const signature = packet.signature || '';

                const expected = crypto.createHmac('sha256', secret)
                    .update(action + timestamp)
                    .digest('hex');

                const sigBuf = Buffer.from(signature, 'hex');
                const expBuf = Buffer.from(expected, 'hex');
                const validSig = sigBuf.length === expBuf.length && crypto.timingSafeEqual(sigBuf, expBuf);

                const freshEnough = typeof timestamp === 'number' && Math.abs(Math.floor(Date.now() / 1000) - timestamp) <= 60;

                if (!validSig || !freshEnough) {
                    console.log(`[socket in] REJECTED action=${action} validSig=${validSig} freshEnough=${freshEnough}`);
                    socket.write(JSON.stringify({ status: 'error', message: 'Unauthorized' }));
                    socket.end();
                    return;
                }

                // Finish guild_chat_outbound here to avoid racing the generic
                // socket.write call below.
                if (action === 'guild_chat_outbound') {
                    const payloadData = packet.params || packet.data || {};
                    const { channel_id, guild, player, message } = payloadData;
                    console.log(`[socket in] guild_chat_outbound -> channel_id=${channel_id} guild=${guild} player=${player}`);
                    const channel = await client.channels.fetch(channel_id).catch((e) => { console.log('[socket in] channel fetch failed:', e.message); return null; });
                    if (channel && channel.isTextBased()) {
                        await channel.send(`**[${guild}] ${player}:** ${message}`).catch((e) => console.log('[socket in] channel send failed:', e.message));
                    } else {
                        console.log(`[socket in] channel not found or not text-based: ${channel_id}`);
                    }
                    socket.write(JSON.stringify({ status: 'ok' }));
                    socket.end();
                    return;
                }
                
                if (action === 'create_guild_channel') {
                    const payloadData = packet.params || packet.data || {};
                    const guildName = payloadData.guild_name || 'Unknown Guild';
                    const discordId = payloadData.discord_id;
                    
                    const serverGuild = client.guilds.cache.first();
                    if (!serverGuild) {
                        socket.write(JSON.stringify({ status: 'error', message: 'Bot is not on any server' }));
                        socket.end();
                        return;
                    }

                    const channelName = guildName.toLowerCase().replace(/[^a-z0-9-]/g, '-');
                    
                    try {
                        const channel = await serverGuild.channels.create({
                            name: channelName,
                            type: 0,
                            permissionOverwrites: [
                                { id: serverGuild.id, deny: ['ViewChannel'] },
                                { id: client.user.id, allow: ['ViewChannel', 'SendMessages'] },
                                { id: discordId, allow: ['ViewChannel', 'SendMessages'] }
                            ]
                        });
                        
                        socket.write(JSON.stringify({ status: 'ok', channel_id: channel.id }));
                    } catch (err) {
                        console.error('[create_guild_channel] Error:', err);
                        socket.write(JSON.stringify({ status: 'error', message: err.message }));
                    }
                    socket.end();
                    return;
                }

                socket.write(JSON.stringify({ status: 'ok' }));
            } catch (e) {
                console.log('[socket in] parse/handling error:', e.message);
            }
            socket.end();
        });
    });

    server.on('error', (e) => console.error('[socket server] error:', e.message));
    server.listen(port, '127.0.0.1', () => {
        console.log(`[socket server] listening on 127.0.0.1:${port}`);
    });
}

initBot();
