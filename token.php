<?php

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$username = optional_param('username', null, PARAM_USERNAME);
$serviceshortname  = required_param('service',  PARAM_ALPHANUMEXT);

echo $OUTPUT->header();

if (!$CFG->enablewebservices) {
    throw new moodle_exception('enablewsdescription', 'webservice');
}
$username = trim(core_text::strtolower($username));

if (is_restored_user($username)) {
    throw new moodle_exception('restoredaccountresetpassword', 'webservice');
}

// Be very picky about who we let in
$remote_addr = getremoteaddr();
$clients = unserialize(get_config('local_ombieltoken', 'clients'));
if (!is_array($clients)) {
    throw new moodle_exception('accessdenied', 'admin');
}

$inlist = false;
foreach($clients as $client) {
    $client = trim($client);
    if (address_in_subnet($remote_addr, $client)) {
        $inlist = true;
        break;
    }
}

if (!$inlist) {
    throw new moodle_exception('accessdenied', 'admin');
}

// Be very picky about who we let in
$services = unserialize(get_config('local_ombieltoken', 'services'));
if (!is_array($services) || !in_array($serviceshortname, $services)) {
    throw new moodle_exception('accessdenied', 'admin');
}

$user = local_ombieltoken_authenticate_user($username);

if (!empty($user)) {
    // Non admin can not authenticate if maintenance mode
    $hassiteconfig = has_capability('moodle/site:config', context_system::instance(), $user);
    if (!empty($CFG->maintenance_enabled) and !$hassiteconfig) {
        throw new moodle_exception('sitemaintenance', 'admin');
    }

    if (isguestuser($user)) {
        throw new moodle_exception('noguest');
    }

    if (empty($user->confirmed)) {
        throw new moodle_exception('usernotconfirmed', 'moodle', '', $user->username);
    }

    // setup user session to check capability
    session_set_user($user);

    //check if the service exists and is enabled
    $service = $DB->get_record('external_services', array('shortname' => $serviceshortname, 'enabled' => 1));
    if (empty($service)) {
        // will throw exception if no token found
        throw new moodle_exception('servicenotavailable', 'webservice');
    }

    //check if there is any required system capability
    if ($service->requiredcapability and !has_capability($service->requiredcapability, context_system::instance(), $user)) {
        throw new moodle_exception('missingrequiredcapability', 'webservice', '', $service->requiredcapability);
    }

    //specific checks related to user restricted service
    if ($service->restrictedusers) {
        $authoriseduser = $DB->get_record('external_services_users',
            array('externalserviceid' => $service->id, 'userid' => $user->id));

        if (empty($authoriseduser)) {
            throw new moodle_exception('usernotallowed', 'webservice', '', $serviceshortname);
        }

        if (!empty($authoriseduser->validuntil) and $authoriseduser->validuntil < time()) {
            throw new moodle_exception('invalidtimedtoken', 'webservice');
        }

        if (!empty($authoriseduser->iprestriction) and !address_in_subnet(getremoteaddr(), $authoriseduser->iprestriction)) {
            throw new moodle_exception('invalidiptoken', 'webservice');
        }
    }

    //Check if a token has already been created for this user and this service
    //Note: this could be an admin created or an user created token.
    //      It does not really matter we take the first one that is valid.
    $tokenssql = "SELECT t.id, t.sid, t.token, t.validuntil, t.iprestriction
              FROM {external_tokens} t
             WHERE t.userid = ? AND t.externalserviceid = ? AND t.tokentype = ?
          ORDER BY t.timecreated ASC";
    $tokens = $DB->get_records_sql($tokenssql, array($user->id, $service->id, EXTERNAL_TOKEN_PERMANENT));

    //A bit of sanity checks
    foreach ($tokens as $key=>$token) {

        /// Checks related to a specific token. (script execution continue)
        $unsettoken = false;
        //if sid is set then there must be a valid associated session no matter the token type
        if (!empty($token->sid)) {
            $session = session_get_instance();
            if (!$session->session_exists($token->sid)){
                //this token will never be valid anymore, delete it
                $DB->delete_records('external_tokens', array('sid'=>$token->sid));
                $unsettoken = true;
            }
        }

        //remove token if no valid anymore
        //Also delete this wrong token (similar logic to the web service servers
        //    /webservice/lib.php/webservice_server::authenticate_by_token())
        if (!empty($token->validuntil) and $token->validuntil < time()) {
            $DB->delete_records('external_tokens', array('token'=>$token->token, 'tokentype'=> EXTERNAL_TOKEN_PERMANENT));
            $unsettoken = true;
        }

        // remove token if its ip not in whitelist
        if (isset($token->iprestriction) and !address_in_subnet(getremoteaddr(), $token->iprestriction)) {
            $unsettoken = true;
        }

        if ($unsettoken) {
            unset($tokens[$key]);
        }
    }

    // if some valid tokens exist then use the most recent
    if (count($tokens) > 0) {
        $token = array_pop($tokens);
    } else {
        if (has_capability('moodle/webservice:createmobiletoken', get_system_context())
                //Note: automatically token generation is not available to admin (they must create a token manually)
                or (!is_siteadmin($user) && has_capability('moodle/webservice:createtoken', get_system_context()))) {
            // if service doesn't exist, dml will throw exception
            $service_record = $DB->get_record('external_services', array('shortname'=>$serviceshortname, 'enabled'=>1), '*', MUST_EXIST);
            // create a new token
            $token = new stdClass;
            $token->token = md5(uniqid(rand(), 1));
            $token->userid = $user->id;
            $token->tokentype = EXTERNAL_TOKEN_PERMANENT;
            $token->contextid = context_system::instance()->id;
            $token->creatorid = $user->id;
            $token->timecreated = time();
            $token->externalserviceid = $service_record->id;
            $tokenid = $DB->insert_record('external_tokens', $token);
            add_to_log(SITEID, 'webservice', 'automatically create user token', '' , 'User ID: ' . $user->id);
            $token->id = $tokenid;
        } else {
            throw new moodle_exception('cannotcreatetoken', 'webservice', '', $serviceshortname);
        }
    }

    // log token access
    $DB->set_field('external_tokens', 'lastaccess', time(), array('id'=>$token->id));

    add_to_log(SITEID, 'webservice', 'sending requested user token', '' , 'User ID: ' . $user->id);

    $usertoken = new stdClass;
    $usertoken->token = $token->token;
    echo json_encode($usertoken);
} else {
    throw new moodle_exception('usernamenotfound', 'moodle');
}

function local_ombieltoken_authenticate_user($username) {
    global $CFG, $DB;

    $authsenabled = get_enabled_auth_plugins();
    $authplugin = get_auth_plugin('cosign');

    if ($username) {
      $user = get_complete_user_data('username', $username, $CFG->mnet_localhost_id);
    } else {
      $user = get_complete_user_data('username', auth_plugin_cosign::get_cosign_username(), $CFG->mnet_localhost_id);
    }

    if ($user) {
        if ($user->auth !== 'cosign') {
            // Invalid auth - we only allow cosign users in this token generator
            add_to_log(SITEID, 'login', 'error', 'index.php', $username);
            return false;
        }
        if (!empty($user->suspended)) {
            add_to_log(SITEID, 'login', 'error', 'index.php', $username);
            error_log('[client '.getremoteaddr()."]  $CFG->wwwroot  Suspended Login:  $username  ".$_SERVER['HTTP_USER_AGENT']);
            return false;
        }
    } else {
        // check if there's a deleted record (cheaply)
        if ($DB->get_field('user', 'id', array('username'=>$username, 'deleted'=>1))) {
            error_log('[client '.getremoteaddr()."]  $CFG->wwwroot  Deleted Login:  $username  ".$_SERVER['HTTP_USER_AGENT']);
        }
        return false;
    }

    $user = update_user_record($username);

    return $user;
}
