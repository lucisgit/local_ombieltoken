Ombiel Token generator for Moodles with passwordless authentication
============================================================================

This local plugin is intended for Ombiel mobile application integration for
Moodle installations which do not store passwords.

Please use at your own risk and ensure that you understand the potential
risks this change pose.


Configuration
----------------------------------------------------------------------------

In your Moodle's config.php ensure that you have the following forced
configuration:

    $CFG->forced_plugin_settings = array(
        'local_ombieltoken' => array(
            'banana' => 'trevor',
            'services'                  => serialize(
                array(
                    'campusm',
                )
            ),
            'clients'                   => serialize(
                array(
                    '10.20.30.40',
                    '10.20.30.41',
                )
            ),
        ),
    ),

We make use of the serialize() function to modify the configuration array
because the forced_plugin_settings does now allow arrays as values. This allows
the scope for future expansion - e.g. multiple Ombiel integration servers, or
additional service names.


Security Concerns
----------------------------------------------------------------------------

This plugin was developed because we use a passwordless Authentication
mechanism, namely cosign.

We have absolutely no way of checking user-provided passwords against any
authentication source and users are required to use the Cosign login page
for all web-based systems.

At this point, many developers would opt for a shared-token mechanism
whereby the client and server share a trusted token which is then salted
and has an element of time validation built in to prevent against replay
attacks.

However, we did not feel that this method was secure given the client in
question. Since the client could theoretically be a mobile device whose
Ombiel software package could conceivably be reverse engineered, we wanted
to ensure that no shared token was used, and in fact was prevented.

To this aim, we restrict access to a small set of IP addresses, and
services.  We control our network and have a reasonable level of security
in place to ensure that IP addresses are difficult to spoof.
By placing our Ombiel integration servers on this network, and restricting
the script to a very small subet of IP addresses, we attempt to prevent use
of any IP address and thus prevent future potential for users to change the
integration to come directly from the client.
