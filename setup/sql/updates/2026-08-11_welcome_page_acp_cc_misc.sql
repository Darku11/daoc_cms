SET NAMES utf8mb4;
START TRANSACTION;

SET @daocsw_home_content = '<!--
DAoC CMS compact post-installation welcome block
Scoped CSS only: safe to embed inside an existing CMS content page.
-->

<style>
.daocsw{
    --daocsw-gold:#c7a04a;
    --daocsw-gold-soft:#dfc87e;
    --daocsw-line:#2a251a;
    --daocsw-panel:#0b0b0d;
    --daocsw-text:#ddd9d1;
    --daocsw-muted:#918d86;
    --daocsw-green:#65c884;

    width:min(880px,100%);
    margin:0 auto;
    padding:14px 0 8px;
    color:var(--daocsw-text);
    font-family:inherit;
    box-sizing:border-box;
}

.daocsw *,
.daocsw *::before,
.daocsw *::after{
    box-sizing:border-box;
}

.daocsw__card{
    border:1px solid var(--daocsw-line);
    border-top:2px solid var(--daocsw-gold);
    background:rgba(11,11,13,.88);
}

.daocsw__main{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:28px;
    align-items:center;
    padding:28px 30px;
}

.daocsw__eyebrow{
    margin:0 0 9px;
    color:var(--daocsw-gold);
    font-size:.68rem;
    font-weight:700;
    letter-spacing:.18em;
    text-transform:uppercase;
}

.daocsw__title{
    margin:0;
    color:var(--daocsw-text);
    font-family:Georgia,"Times New Roman",serif;
    font-size:clamp(1.65rem,3vw,2.35rem);
    line-height:1.08;
    font-weight:400;
    letter-spacing:.02em;
}

.daocsw__title strong{
    color:var(--daocsw-gold-soft);
    font-weight:400;
}

.daocsw__lead{
    margin:12px 0 0;
    max-width:630px;
    color:var(--daocsw-muted);
    font-size:.9rem;
    line-height:1.62;
}

.daocsw__status{
    display:inline-flex;
    align-items:center;
    gap:8px;
    white-space:nowrap;
    color:#bdb8ae;
    font-size:.72rem;
    font-weight:700;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.daocsw__status::before{
    content:"✓";
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    border:1px solid #31563e;
    background:#0d1a12;
    color:var(--daocsw-green);
    font-size:.95rem;
}

.daocsw__next{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    border-top:1px solid var(--daocsw-line);
}

.daocsw__item{
    padding:18px 24px;
    color:var(--daocsw-muted);
    font-size:.8rem;
    line-height:1.55;
}

.daocsw__item + .daocsw__item{
    border-left:1px solid var(--daocsw-line);
}

.daocsw__item b{
    display:block;
    margin-bottom:4px;
    color:var(--daocsw-gold-soft);
    font-family:Georgia,"Times New Roman",serif;
    font-size:.92rem;
    font-weight:400;
}

.daocsw__actions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    padding:18px 24px;
    border-top:1px solid var(--daocsw-line);
}

.daocsw__button{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    min-height:40px !important;
    padding:0 16px !important;
    border:1px solid #66501f !important;
    background:#0c0b09 !important;
    color:var(--daocsw-gold-soft) !important;
    opacity:1 !important;
    text-shadow:none !important;
    text-decoration:none !important;
    font-size:.71rem !important;
    font-weight:700 !important;
    letter-spacing:.11em !important;
    line-height:1 !important;
    text-transform:uppercase !important;
}

.daocsw__button:hover{
    border-color:var(--daocsw-gold) !important;
    background:#121008 !important;
    color:#f3db94 !important;
}

.daocsw__button--primary{
    background:var(--daocsw-gold) !important;
    border-color:var(--daocsw-gold) !important;
    color:#0a0906 !important;
}

.daocsw__button--primary:hover{
    background:var(--daocsw-gold-soft) !important;
    border-color:var(--daocsw-gold-soft) !important;
    color:#090805 !important;
}

.daocsw__docs{
    margin-left:auto;
    align-self:center;
    color:#746f65;
    font-size:.72rem;
}

.daocsw__docs a{
    color:var(--daocsw-gold-soft) !important;
    text-decoration:none !important;
}

.daocsw__docs a:hover{
    text-decoration:underline !important;
}

@media (max-width:700px){
    .daocsw__main{
        grid-template-columns:1fr;
        gap:18px;
        padding:24px 20px;
    }

    .daocsw__next{
        grid-template-columns:1fr;
    }

    .daocsw__item + .daocsw__item{
        border-left:0;
        border-top:1px solid var(--daocsw-line);
    }

    .daocsw__actions{
        padding:16px 20px;
    }

    .daocsw__docs{
        width:100%;
        margin-left:0;
    }
}
</style>

<div class="daocsw">
    <section class="daocsw__card">

        <div class="daocsw__main">
            <div>
                <div class="daocsw__eyebrow">Installation complete</div>

                <h2 class="daocsw__title">
                    Your <strong>DAoC CMS</strong> is ready.
                </h2>

                <p class="daocsw__lead">
                    Setup is complete. Before going public, review the basic CMS settings
                    and make sure your Dawn of Light connection is configured.
                </p>
            </div>

            <div class="daocsw__status">Ready</div>
        </div>

        <div class="daocsw__next">
            <div class="daocsw__item">
                <b>1. General Settings</b>
                Site identity, language and enabled modules.
            </div>

            <div class="daocsw__item">
                <b>2. DOL Integration</b>
                Verify the database connection before using game-linked tools.
            </div>
        </div>

        <div class="daocsw__actions">
            <a class="daocsw__button daocsw__button--primary" href="acp.php">
                Open Control Panel
            </a>

            <div class="daocsw__docs">
                Need help?
                <a href="https://aldhran-server.eu/index.php?p=spike">Open documentation</a>
            </div>
        </div>

    </section>
</div>';

UPDATE `pages`
SET `content` = @daocsw_home_content
WHERE `slug` = 'home'
  AND `title` = 'You are all set!'
  AND `content` LIKE '%setup-finish-page%';

INSERT INTO `cms_translations` (`lang_code`, `var_context`, `var_key`, `var_value`) VALUES
    ('de', 'core', 'acp_cc_misc', 'Links/Module/Seiten anzeigen'),
    ('es', 'core', 'acp_cc_misc', 'Mostrar enlaces/módulos/páginas'),
    ('it', 'core', 'acp_cc_misc', 'Mostra link/moduli/pagine')
ON DUPLICATE KEY UPDATE
    `var_context` = VALUES(`var_context`),
    `var_value` = VALUES(`var_value`);

INSERT INTO `settings` (`setting_key`, `value`)
VALUES ('settings_version', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

COMMIT;
