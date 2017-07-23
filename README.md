INTRODUCTION
============

This App provides CAS authentication support, using the phpcas library of jasig/apereo.


INSTALLATION
============

DEPENDENCIES
-------------------

* Owncloud >= 10.0
* PHP >= 5.6, PHP 7 if possible
* [Composer Dependency Manager](https://getcomposer.org/)

This app does not require a standalone version of jasig’s/apereo’s phpcas any longer. The library is shipped within composer dependencies. Although you can configure to use your own version of jasig’s/apereo’s phpcas library later on.


STEPS
-----

1. Git clone/copy the `user_cas` folder into the Owncloud's apps folder and make sure to set correct permissions for your Webserver.
2. Change directory inside `user_cas` folder after cloning/copying and perform a `composer update` command. The dependencies will be installed. Attention: You will need the [composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx) binary to be installed
2. Access the Owncloud web interface with a locally created Owncloud user with admin privileges.
3. Access the administrations panel => Apps and enable the **CAS user and group backend** app.
4. Access the administration panel => Additional and configure the app.


CONFIGURATION
=============

The app is configured by using the administration panel. Please make sure to configure with an admin user, authenticated locally against your Owncloud instance (and not against CAS). Make sure to fill in all the fields provided.


CAS Server
----------

**CAS Server Version**: Default is CAS version 2.0, if you have no special configuration leave it as is.

**CAS Server Hostname**: The host name of the webserver hosting your CAS, lookup /etc/hosts or your DNS configuration or ask your IT-Department.

**CAS Server Port**: The port CAS is listening to. Default for HTTPS is `443`.

**CAS Server Path**: The directory of your CAS. In common setups this path is `/cas`. 

**Service URL**: Service URL used to CAS authentication and redirection. Useful when behind a reverse proxy.

**Certification file path (.crt)**: If you don't want to validate the certificate (i.e. self-signed certificates) then leave this blank. Otherwise enter the path to the certificate (.crt) on your server, beginning at root level.


Basic
-----

**Force user login using CAS?**: If checked, users will immediately be redirected to CAS’ login page, after visiting the Owncloud URL. If checked, **Disable CAS logout** is automatically disabled. Default: off

**Disable CAS logout**: If checked, you will only be logged out from Owncloud and not from your CAS instance. Default: off

**Autocreate user after first CAS login?**: Ich checked, users authenticated against CAS are automatically created. This means, users which did not exist in the database yet, authenticate against CAS and the app will create and store them in the Owncloud database on their first login. Default: on

**Update user data after each CAS login?**: If checked, the data provided by CAS is used to update Owncloud user attributes each time the user logs in. Default: on

**Groups that will not be unlinked**: These groups are preserved, when updating a user after login and are not unlinked. Default: empty

**Default group when autocreating users**: When auto creating users after authentication, these groups are set as default if the user has no CAS groups. Default: empty

<!-- **Link to LDAP backend**: Link CAS authentication with LDAP users and groups backend to use the same owncloud user as if the user was logged in via LDAP. -->


<a name="mapping"></a>

Mapping
-------

If CAS provides extra attributes, `user_cas` can retrieve the values of them. Since their name differs in various setups it is necessary to map owncloud-attribute-names to CAS-attribute-names.

**Email**: Name of email attribute in CAS. Default: empty

**Display Name**: Name of display name attribute in CAS (this might be the "real name" of a user). Default: empty

**Group**: Name of group attribute in CAS. Default: empty


PHP-CAS Library
---------------

Setting up the PHP-CAS library options :

**PHP CAS path (CAS.php file)**: Set a custom path to a CAS.php file of the jasig/phpcas library version you want to use. Beginning at rootlevel of your server. Default: empty, meaning it uses the composer installed dependency in the `user_cas` folder.

**PHP CAS debug file**: Set path to a custom phpcas debug file. Beginning at rootlevel of your server. Default: empty

EXTRA INFO
==========

* If you enable the "Autocreate user after CAS login" option, a user will be created if he does not exist. If this option is disabled and the user does not exist, then the user will be not allowed to log into Owncloud. <!-- You might not want this if you check "Link to LDAP backend" -->

* If you enable the "Update user data" option, the app updates the user's Display Name, Email and Group Membership and overwrites manually changed data in the Owncloud users table.

By default the CAS App will unlink all the groups from a user and will provide the groups defined at the [**Mapping**](#mapping) attributes. If this mapping is not defined, the value of the **Default group when autocreating users** field will be used instead. If both are undefined, then the user will be set with no groups.
If you set the "protected groups" field, those groups will not be unlinked from the user.

Bugs and Support
==============

Please contribute bug reports and feedback to [GitHub Issues](https://github.com/felixrupp/user_cas/issues).  
If you are observing undesired behaviour, think it is a bug and want to tell me about, please include following parts:
* What led up to the situation?
* What exactly did you do (or not do) that was effective (or ineffective)?
* What was the outcome of this action?
* What outcome did you expect instead?

Also please provide basic information of your Owncloud instance:
* Owncloud Version
* PHP Version
* CAS Version
* phpcas library version
* The part of the owncloud.log file, from -5min. before and +5min. after the bug happened

ABOUT
=====

License
-------

AGPL - http://www.gnu.org/licenses/agpl-3.0.html

Authors
-------

Current Version, since 1.4.0:
* Felix Rupp - https://github.com/felixrupp

Older Versions:
* Sixto Martin Garcia - https://github.com/pitbulk
* David Willinger (Leonis Holding)  - https://github.com/leoniswebDAVe
* Florian Hintermeier (Leonis Holding)  - https://github.com/leonisCacheFlo
* brenard - https://github.com/brenard

Links
-------
* Owncloud - http://owncloud.org
* Owncloud @ GitHub - https://github.com/owncloud