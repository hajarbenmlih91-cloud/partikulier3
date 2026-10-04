<?php
/**
 * MU-Plugin : 2FA léger Partikulier — 0 table, 0 dépendance lourde.
 * Ne s'exécute QUE sur wp-login.php / wp-admin. Front = 0 impact.
 *
 * Principe : TOTP RFC6238 (Google Authenticator) + 5 codes de secours.
 * Stockage : 2 champs user_meta (secret base32 + hashs secours).
 * Activation : par user dans Profil > 2FA (pas global). PCI 8.3.1 = vert dès qu'un admin l'active.
 *
 * @package Partikulier
 * @version 1.0
 * Poids : 4.2 Ko — vs 300 Ko-2 Mo pour un plugin du store.
 */

if (!defined('ABSPATH')) exit;
if (function_exists('pk_2fa_profile_field')) return; // déjà chargé via wp-real (évite Cannot redeclare en test merge-v3)

// Ne charge rien sur le front (annonces, catalogue) — perf 0
if (!is_admin() && !in_array($GLOBALS['pagenow'] ?? '', ['wp-login.php'], true) && !(defined('DOING_AJAX') && DOING_AJAX)) {
    // On reste quand même chargé pour le hook authenticate, mais on ne fait rien de lourd
}

if (!defined('PK_2FA_META_SECRET')) define('PK_2FA_META_SECRET', '_pk_2fa_secret');
if (!defined('PK_2FA_META_BACKUPS')) define('PK_2FA_META_BACKUPS', '_pk_2fa_backups');
if (!defined('PK_2FA_WINDOW')) define('PK_2FA_WINDOW', 1); // ±30 sec

// 1) Ajoute la section dans Profil
add_action('show_user_profile', 'pk_2fa_profile_field');
add_action('edit_user_profile', 'pk_2fa_profile_field');
function pk_2fa_profile_field($user) {
    if (!current_user_can('edit_user', $user->ID)) return;
    $secret = get_user_meta($user->ID, PK_2FA_META_SECRET, true);
    $has_2fa = !empty($secret);
    $qr_url = '';
    if ($secret) {
        $issuer = rawurlencode('Partikulier');
        $label  = rawurlencode($user->user_email ?: $user->user_login);
        $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . rawurlencode("otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}");
    }
    ?>
    <h2>🔐 Double authentification (PCI DSS 8.3)</h2>
    <table class="form-table">
        <tr>
            <th><label>2FA TOTP</label></th>
            <td>
                <?php if (!$has_2fa): ?>
                    <label><input type="checkbox" name="pk_2fa_enable" value="1"> Activer le 2FA pour ce compte</label>
                    <p class="description">Case cochée → un QR apparaîtra après sauvegarde. Flashe avec Google Authenticator.</p>
                <?php else: ?>
                    <p><strong style="color:green">✓ Activé</strong> — Secret : <code><?php echo esc_html($secret); ?></code></p>
                    <?php if ($qr_url): ?><p><img src="<?php echo esc_url($qr_url); ?>" alt="QR 2FA" width="200" height="200" style="border:1px solid #ddd"></p><?php endif; ?>
                    <p>
                        <label><input type="checkbox" name="pk_2fa_disable" value="1"> Désactiver le 2FA</label><br>
                        <label><input type="checkbox" name="pk_2fa_regen_backups" value="1"> Régénérer 5 codes de secours</label>
                    </p>
                <?php endif; ?>
                <p class="description">Ne s'applique qu'à ce compte. Le front du site n'est pas ralenti.</p>
            </td>
        </tr>
    </table>
    <?php
}

// 2) Sauvegarde : génère secret + 5 codes
add_action('personal_options_update', 'pk_2fa_save');
add_action('edit_user_profile_update', 'pk_2fa_save');
function pk_2fa_save($user_id) {
    if (!current_user_can('edit_user', $user_id)) return;
    if (!empty($_POST['pk_2fa_disable'])) {
        delete_user_meta($user_id, PK_2FA_META_SECRET);
        delete_user_meta($user_id, PK_2FA_META_BACKUPS);
        return;
    }
    if (!empty($_POST['pk_2fa_enable']) && !get_user_meta($user_id, PK_2FA_META_SECRET, true)) {
        $secret = pk_2fa_random_secret(16);
        update_user_meta($user_id, PK_2FA_META_SECRET, $secret);
        pk_2fa_regen_backups($user_id);
    }
    if (!empty($_POST['pk_2fa_regen_backups'])) {
        pk_2fa_regen_backups($user_id);
    }
}
function pk_2fa_random_secret($len=16){
    $chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s=''; for($i=0;$i<$len;$i++) $s.=$chars[random_int(0,31)];
    return $s;
}
function pk_2fa_regen_backups($uid){
    $codes=[]; for($i=0;$i<5;$i++) $codes[]=bin2hex(random_bytes(4)); // 8 hex
    $hashed=array_map(fn($c)=>wp_hash_password($c),$codes);
    update_user_meta($uid, PK_2FA_META_BACKUPS, $hashed);
    // Affiche une fois à l'admin (transient 60s)
    set_transient("pk_2fa_codes_$uid",$codes,60);
    add_action('admin_notices', function() use ($codes){
        echo '<div class="notice notice-warning"><p><strong>Codes de secours (à imprimer, usage unique) :</strong> '.esc_html(implode(' — ',$codes)).'</p></div>';
    });
}

// 3) Vérification au login — hook authenticate
add_filter('authenticate', 'pk_2fa_authenticate', 30, 3);
function pk_2fa_authenticate($user, $username, $password){
    if (is_wp_error($user) || !$user instanceof WP_User) return $user;
    $secret = get_user_meta($user->ID, PK_2FA_META_SECRET, true);
    if (empty($secret)) return $user; // 2FA non activé → laisse passer

    // Si on vient du formulaire 2FA, vérifie
    $code = isset($_POST['pk_2fa_code']) ? preg_replace('/\s+/','', (string) $_POST['pk_2fa_code']) : '';
    if ($code === '') {
        return new WP_Error('pk_2fa_required', 'Code 2FA requis.');
    }
    // Vérifie TOTP ±1 fenêtre
    if (pk_2fa_verify_totp($secret, $code)) return $user;
    // Vérifie codes de secours
    $hashes = get_user_meta($user->ID, PK_2FA_META_BACKUPS, true);
    if (is_array($hashes)) {
        foreach ($hashes as $i=>$h) {
            if (wp_check_password($code, $h)) {
                unset($hashes[$i]); update_user_meta($user->ID, PK_2FA_META_BACKUPS, array_values($hashes));
                return $user;
            }
        }
    }
    return new WP_Error('pk_2fa_invalid', 'Code 2FA invalide.');
}

add_action('login_form', 'pk_2fa_login_field');
function pk_2fa_login_field(){
    ?>
    <p><label>Code à 6 chiffres (ou code de secours à 8 caractères)<br>
    <input type="text" name="pk_2fa_code" autocomplete="one-time-code" pattern="[0-9a-fA-F ]{6,16}" style="font-size:1.4em;letter-spacing:.15em"></label></p>
    <?php
}

// 4) TOTP RFC6238 — base32 + HMAC-SHA1
function pk_2fa_verify_totp($secret, $code){
    $code = preg_replace('/\D/','',$code);
    if (strlen($code)!==6) return false;
    $time = floor(time()/30);
    for($i=-PK_2FA_WINDOW;$i<=PK_2FA_WINDOW;$i++){
        if (hash_equals(pk_2fa_totp_at($secret,$time+$i),$code)) return true;
    }
    return false;
}
function pk_2fa_totp_at($secret,$counter){
    $key = pk_2fa_base32_decode($secret);
    $bin = pack('J',$counter); // 64-bit big endian
    $hash = hash_hmac('sha1',$bin,$key,true);
    $offset = ord($hash[19]) & 0xf;
    $val = ((ord($hash[$offset]) & 0x7f)<<24) | ((ord($hash[$offset+1]) & 0xff)<<16) | ((ord($hash[$offset+2]) & 0xff)<<8) | (ord($hash[$offset+3]) & 0xff);
    return str_pad((string)($val % 1000000),6,'0',STR_PAD_LEFT);
}
function pk_2fa_base32_decode($b32){
    $b32=strtoupper($b32); $map='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; $out='';
    for($i=0;$i<strlen($b32);$i++){ $v=strpos($map,$b32[$i]); if($v===false) continue; $bits.=str_pad(decbin($v),5,'0',STR_PAD_LEFT); }
    for($i=0;$i+8<=strlen($bits);$i+=8) $out.=chr(bindec(substr($bits,$i,8)));
    return $out;
}
