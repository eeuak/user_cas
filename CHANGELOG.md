CHANGELOG
=========

Version 1.5.0
-------------
* Drop onwCloud 9 support
* Major source code optimizations, fix several Errors associated with redirection after login
* Add ECAS support
* Add authorization feature via groups
* Add error views for when not authorized or when the CAS-Client throws errors
* Support for ownCloud oauth2 App, it’s now possible to authenticate a desktop or mobile phone client with CAS via oauth2 provider

Version 1.4.9
-------------
* Hotfixes the autocreate bug, mentioned in Issue [#13](https://github.com/felixrupp/user_cas/issues/13).

Version 1.4.8
-------------
* Hotfixes the current 1.4 version to fix a major bug preventing the OCS-Api to work, while the user_cas app is installed and enabled.

Version 1.4.7
-------------
* Hotfixes the min version and **lowers it to 9.1.6**

Version 1.4.6
-------------
* Hotfix for app initialization

Version 1.4.5
-------------
* Fix for autocreate bug
* Re-add phpcas path to use custom phpcas library, if wanted
* Remove GIT submodule for jasig phpcas
* Add composer dependencies instead
* **Raise minimum Owncloud Version to 10.0**

Version 1.4.2, 1.4.3, 1.4.4
---------------------------
* Hotfixes for logging

Version 1.4.1
-------------
* Hotfix for group and protected group handling

Version 1.4.0
-------------
* Completely rewritten in object oriented code, based on Owncloud 9.1 app programming guidelines

Version 0.1.1
-------------
* Added CSRF protection on setting form
* Use openssl_random_pseudo_bytes instead of mt_rand (if available)

Version 0.1
-------------
* Initial plugin
