<?php
/**
 * DISCORD OAUTH2 LOGIC - DAoC CMS
 */
if (!defined('IN_CMS')) exit;

$token_url = "https://discord.com/api/oauth2/token";
$user_url  = "https://discord.com/api/users/@me";

// Validate CSRF state (must have been set in the session before the OAuth handshake)
if (
    empty($_GET['state']) ||
    empty($_SESSION['discord_oauth_state']) ||
    !hash_equals($_SESSION['discord_oauth_state'], $_GET['state'])
) {
    aldhran_log("DISCORD_CSRF_FAIL", "OAuth2 state mismatch");
    die("Discord OAuth failed: invalid state parameter.");
}
unset($_SESSION['discord_oauth_state']);

// Pulled from config.php - define DISCORD_CLIENT_ID / DISCORD_CLIENT_SECRET there
if (!defined('DISCORD_CLIENT_ID') || !defined('DISCORD_CLIENT_SECRET')) {
    aldhran_log("DISCORD_CONFIG_MISSING", "DISCORD_CLIENT_ID/DISCORD_CLIENT_SECRET not configured");
    die("Discord OAuth is not configured on this server.");
}

$data = [
    'client_id'     => DISCORD_CLIENT_ID,
    'client_secret' => DISCORD_CLIENT_SECRET,
    'grant_type'    => 'authorization_code',
    'code'          => $_GET['code'] ?? '',
    'redirect_uri'  => SITE_URL . '/index.php?p=discord_callback',
    'scope'         => 'identify'
];

if (empty($data['code'])) {
    die("Invalid Discord request.");
}

// 1. Exchange the authorization code for a Discord token through cURL.
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
$response = json_decode(curl_exec($ch), true);

if (!isset($response['access_token'])) {
    curl_close($ch);
    aldhran_log("DISCORD_HANDSHAKE_FAIL", "OAuth2 Token request failed");
    die("Discord handshake failed.");
}

$access_token = $response['access_token'];

// 2. Retrieve Discord user information with the token.
$header = ["Authorization: Bearer $access_token"];
curl_setopt($ch, CURLOPT_URL, $user_url);
curl_setopt($ch, CURLOPT_POST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
$user_data = json_decode(curl_exec($ch), true);
curl_close($ch);

if (isset($user_data['id'])) {
    $discord_id   = $user_data['id'];
    $discord_name = $user_data['username'];

    // 3. Check the DB via PDO
    $stmt_check = $db->prepare("SELECT id, username, priv_level, standing FROM users WHERE discord_id = ?");
    $stmt_check->execute([$discord_id]);
    $user = $stmt_check->fetch();

    if ($user) {
        // Check if the user is permanently banned (Standing 5)
        if ((int)$user['standing'] >= 5) {
            aldhran_log("DISCORD_LOGIN_BANNED", "Banned user tried Discord login: $discord_name");
            die("Your access to DAoC CMS has been permanently suspended.");
        }

        // Perform login
        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['priv_level']    = (int)$user['priv_level'];
        $_SESSION['user_standing'] = (int)$user['standing'];

        // Audit logging.
        aldhran_log("DISCORD_LOGIN_SUCCESS", "User logged in via Discord", $user['id']);

        header("Location: index.php?p=home&msg=discord_welcome");
        exit;
    } else {
        // Not linked yet? Send to registration
        $_SESSION['temp_discord_id']   = $discord_id;
        $_SESSION['temp_discord_name'] = $discord_name;

        aldhran_log("DISCORD_AUTH_NEW", "Discord ID recognized, proceeding to linking");
        header("Location: index.php?p=register&mode=discord");
        exit;
    }
} else {
    aldhran_log("DISCORD_USERINFO_FAIL", "Could not retrieve Discord user info");
    die("Could not retrieve Discord user information.");
}
exit;
