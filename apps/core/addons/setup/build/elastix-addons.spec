%define modname addons

Summary: Elastix Addons 
Name:    elastix-%{modname}
Version: 2.3.0
Release: 4
License: GPL
Group:   Applications/System
Source0: %{modname}_%{version}-%{release}.tgz
#Source0: %{modname}_%{version}-3.tgz
BuildRoot: %{_tmppath}/%{name}-%{version}-root
BuildArch: noarch
Prereq: elastix-framework >= 2.3.0-5
Prereq: chkconfig, php-soap
Requires: yum

%description
Elastix Addons

%prep
%setup -n %{modname}

%install
rm -rf $RPM_BUILD_ROOT

# Files provided by all Elastix modules
mkdir -p    $RPM_BUILD_ROOT/var/www/html/
mv modules/ $RPM_BUILD_ROOT/var/www/html/

# Additional (module-specific) files that can be handled by RPM
mkdir -p $RPM_BUILD_ROOT/opt/elastix/
mv setup/elastix-moduleconf $RPM_BUILD_ROOT/opt/elastix/elastix-updater
mkdir -p $RPM_BUILD_ROOT/etc/init.d/
mv $RPM_BUILD_ROOT/opt/elastix/elastix-updater/elastix-updaterd $RPM_BUILD_ROOT/etc/init.d/
chmod +x $RPM_BUILD_ROOT/etc/init.d/elastix-updaterd
mkdir -p $RPM_BUILD_ROOT/etc/yum.repos.d/

# The following folder should contain all the data that is required by the installer,
# that cannot be handled by RPM.
mkdir -p    $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
mv setup/etc/yum.repos.d/commercial-addons.repo $RPM_BUILD_ROOT/etc/yum.repos.d/
mv setup/   $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
mv menu.xml $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/

%pre
mkdir -p /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
touch /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/preversion_%{modname}.info
if [ $1 -eq 2 ]; then
    rpm -q --queryformat='%{VERSION}-%{RELEASE}' %{name} > /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/preversion_%{modname}.info
fi

%post
pathModule="/usr/share/elastix/module_installer/%{name}-%{version}-%{release}"

# Run installer script to fix up ACLs and add module to Elastix menus.
elastix-menumerge /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/menu.xml
pathSQLiteDB="/var/www/db"
mkdir -p $pathSQLiteDB
preversion=`cat $pathModule/preversion_%{modname}.info`

if [ $1 -eq 1 ]; then #install
  # The installer database
    elastix-dbprocess "install" "$pathModule/setup/db"
elif [ $1 -eq 2 ]; then #update
    # Removing addons_installed modules
    elastix-menuremove "addons_installed"
    elastix-menuremove "addons_avalaibles"
    # Removing addons_installed files
    rm -rf /var/www/html/modules/addons_installed
    elastix-dbprocess "update"  "$pathModule/setup/db" "$preversion"
    # restart daemon
    /sbin/service elastix-updaterd restart
fi


# The installer script expects to be in /tmp/new_module
mkdir -p /tmp/new_module/%{modname}
cp -r /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/* /tmp/new_module/%{modname}/
chown -R asterisk.asterisk /tmp/new_module/%{modname}

php /tmp/new_module/%{modname}/setup/installer.php
rm -rf /tmp/new_module

# Install elastix-updaterd as a service
chkconfig --add elastix-updaterd
chkconfig --level 2345 elastix-updaterd on

%clean
rm -rf $RPM_BUILD_ROOT

%preun
pathModule="/usr/share/elastix/module_installer/%{name}-%{version}-%{release}"
if [ $1 -eq 0 ] ; then # Validation for desinstall this rpm
  echo "Delete Addons menus"
  elastix-menuremove "%{modname}"

  echo "Dump and delete %{name} databases"
  elastix-dbprocess "delete" "$pathModule/setup/db"
fi

%files
%defattr(-, asterisk, asterisk)
%{_localstatedir}/www/html/*
/usr/share/elastix/module_installer/*
/opt/elastix/elastix-updater/*
%defattr(-, root, root)
/etc/init.d/elastix-updaterd
/etc/yum.repos.d/*

%changelog
* Fri Jun 15 Alex Villacis Lasso <a_villacis@palosanto.com>
- CHANGED: Explicitly return exit code in startup script
- CHANGED: /etc/init.d/elastix-updaterd should be owned by root, not asterisk.

* Thu Jun 07 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- CHANGED: Elastix Updater: remove lone call to deprecated ereg().

* Mon Jun 04 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- CHANGED: Elastix Updater: remove E_STRICT from error_reporting to silence
  warning messages at daemon startup in Fedora 17.

* Fri Apr 27 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-4
- CHANGED: Addons - Build/elastix-addons.spec: update specfile with latest
  SVN history. Changed release in specfile

* Thu Apr 25 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Addons: the elastix updater daemon should explicitly set the default 
  timezone.
  SVN Rev[3884]
- CHANGED: modules addons_availables, added a min-height of 105px to the class 
  .neo-addons-row
  SVN Rev[3827]
- CHANGED: modules addons_availables, in class neo-addons-row deleted the line
  "height:120px;" so the row for each addon can increase depending on its 
  description.
  SVN Rev[3826]

* Mon Apr 2 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-3
- FIXED: Addons Availables: parameter to $json->encode() is not optional.
  SVN Rev[3807]

* Fri Mar 30 2012 Bruno Macias <bmacias@palosanto.com> 2.3.0-2
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-5
- FIXED: modules - SQLs DB: se quita SQL redundante de alter table y nuevos 
  registros, esto causaba un error leve en la instalación de el/los modulos.
  SVN Rev[3797]

* Wed Mar 03 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-1
- CHANGED: In spec file, changed prereq elastix-framework 
  >= 2.3.0-1
- CHANGED: modules addons_availables, added a new div for addons that have
  "notes".
  SVN Rev[3687]
- FIXED: modules addons_availables, when an addon is installed, the 
  action "buy" can not be performed. Now the action "buy" can be 
  performed wether the addon is installed or not
  SVN Rev[3682]

* Mon Jan 30 2012 Rocio Mera <rmera@palosanto.com> 2.2.0-12
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-29
- CHANGED: modules addons_availables, added a style of text-align:justify 
  to addon text description. SVN Rev[3588].

* Fri Jan 27 2012 Rocio Mera <rmera@palosanto.com> 2.2.0-11
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-28
- CHANGED: modules - images: icon image title was changed on some 
  modules. SVN Rev [3572].
- CHANGED: modules - icons: Se cambio de algunos módulos los iconos 
  que los representaba. SVN Rev[3563]
- CHANGED: modules addons_availables, changed part of the text of
  the embedded help. SVN Rev [3535]

* Thu Jan 17 2012 Rocio Mera <rmera@palosanto.com> 2.2.0-10
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-26
- FIXED: modules addons_availables, in function _compareRpmVersion of library paloSantoAddons.class.php have
  to make a return of function _compareRpmVersion_string when the versions are equal. SVN[3529]
- CHANGED: module addons_availables, in case the daemon send a warning message this is displayed in the 
  interface. SVN[3520]

* Tue Jan 03 2012 Alberto Santos <asantos@palosanto.com> 2.2.0-9
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-25
- CHANGED: modules addons_availables, added opacity to pagination
  buttons, this give to the user the illusion of disable
  SVN Rev[3503]
- ADDED: modules addons_availables, added the embedded help
  for this module
  SVN Rev[3494]
- CHANGED: modules addons_availables, added support to
  translations in this module
  SVN Rev[3492]
- ADDED: modules addons_availables, added image searchw.png used
  as icon for the filter "Name"
  SVN Rev[3491]

* Mon Dec 26 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-8
- CHANGED: modules addons_availables, when none addon match with 
  the filter criteria an empty table is shown. Now is displayed 
  the message "No addons match your search criteria". 
  SVN Rev[3487]
- CHANGED: module addons_available, when a transaction is 
  cancelled, now the page is not redirected to store.
  SVN Rev[3486]
- CHANGED: new redesign of module addons. SVN Rev[3484]
- FIXED: ElastixInstallerProcess.class.php, for some unistalled 
  transactions the daemon was not detecting the package.
  SVN Rev[3483]
- CHANGED: daemon ElastixInstallerProcess, when the daemon is 
  in action depsolving, the proccess can also be canceled. 
  SVN Rev[3481]

* Tue Dec 20 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-7
- CHANGED: In spec file changed Prereq elastix to
  elastix-framework >= 2.2.0-23
- CHANGED: Addons: run yum shell through close-on-exec.pl so 
  that it will not inherit socket descriptors. SVN Rev[3444]
- CHANGED: Addons: after 'check' command, action is now set to 
  'none' so that yum can shutdown after timeout. SVN Rev[3444]
- CHANGED: Addons: add a new status line showing how many times the
  custom status has changed its value. SVN Rev[3443]
- CHANGED: Addons: implement new update helper commands addconfirm, 
  updateconfirm, removeconfirm. SVN Rev[3442]
- CHANGED: Addons: document commands yumoutput, yumerror, setcustom, 
  getcustom, addconfirm, updateconfirm, removeconfirm. SVN Rev[3242]
- FIXED: Addons: prevent division by zero in progressbar time 
  calculation. SVN Rev[3441]
- FIXED: Addons: move version string construction to point after 
  check of whether version array is valid. SVN Rev[3440]
- CHANGED: Addons: Request compression of SOAP response for addons 
  webservice. Easy way to speed up rendering. SVN Rev[3432]

* Thu Dec 08 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-6
- CHANGED: In spec file changed Prereq elastix to
  elastix-framework >= 2.2.0-21
- FIXED: Elastix Updater: subsys lock file must have the same 
  name as the service in /etc/init.d in order to shutdown properly.
  SVN Rev[3416]

* Fri Nov 25 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-5
- CHANGED: In spec file changed Prereq elastix to
  elastix-framework >= 2.2.0-18

* Sat Oct 29 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-4
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-13

* Sat Oct 29 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-3
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-12
- CHANGED: Modules -Addons: Added css property border-radius in 
  addons's styles. SVN Rev[3224]
- UPDATED: addons modules  templates files support new elastixneo 
  theme. SVN Rev[3161]

* Fri Oct 07 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-2
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-8
- NEW: Modules - Addons Availables: Add new field in table
  action_tmp from addons.db, this parameter "init_time" has
  the time remaining to download an addon.
  SVN Rev[3051]
- FIXED: Modules - Addons Availables: Bugs about registration 
  process from addons availables module. This bug doesn't show 
  the correct link to buy an addons with the parameter "refered"
  by GET Request.
  CHANGED: Modules - Addons Availables: Add property to see the 
  download remaining time of an addon.
  SVN Rev[3048]
- FIXED: Modules - Addons: Addons daemon was in status "off"
  despite of the process id is running. The problem occur in the
  moment to install the queuemetrics addon and after this process
  the daemon is down. 
  SVN Rev[3030]

* Wed Sep 28 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-1
- CHANGED: module addons_availables, changed word "Upgrade" to "Update"
  SVN Rev[3016]
- ADDED: module addons_available, added the location information
  of the addon
  SVN Rev[3012]

* Fri Jun 24 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-15
- CHANGED: In spec file change prereq elastix >= 2.0.4-25
- CHANGED: Addons: Change the label "Downloading" to 
  "Initializing Donwload" in a process to install a addon when 
  appear the bar "downloading". SVN Rev[2746]

* Thu Jun 23 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-14
- FIXED: Addons: Catch and report network errors that occur 
  while refreshing a repository (add PACKAGE when repos are not 
  accessible). SVN Rev[2736]

* Mon Jun 06 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-13
- CHANGED: Addons - Addons Availables: Fixed problems with css 
  where titles of addons are overwhelmed. SVN Rev[2699]

* Mon Jun 06 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-12
- CHANGED: Addons - Addons Availables: Changes in lang files for 
  translations, and improving toDoClean method in index.php. 
  SVN Rev[2696]

* Fri Jun 03 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-11
- FIXED: Addons - Addons Availables:  Fixed bug where appear a 
  warning for Smarty. SVN Rev[2686]
- CHANGED: Addons - Addons Availables: Changed messages of error 
  in lang files. SVN Rev[2685]

* Tue May 31 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-10
- FIXED:  Module addons: Fixed where button "try it" doesn't 
  appear because it needs the elastix serverkey to be showing.
  SVN Rev[2672]

* Tue May 31 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-9
- FIXED: Addons : Comment line baseurl in commercial-addons.repo.
  SVN Rev[2663]

* Thu May 26 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-8
- CHANGED: The split function of these modules was replaced by the 
  explode function due to that the split function was deprecated 
  since PHP 5.3.0. SVN Rev[2655][2650]
- NEW:    Addons - Addons_availables: Move the libs from addons_installed 
  module to the addons_availables modules.
  DELETE: Addons - Addons_installed:  Remove module addons installed 
  because all funtionality is in addons Availables. SVN Rev[2654]
- CHANGED:  modules - addons: New changes in module addons_availables, 
  changes applied to create ELASTIX MARKET PLACE. SVN Rev[2653]

* Wed Apr 27 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-7
- CHANGED: file db.info, changed installation_force to ignore_backup
  SVN Rev[2488]
- CHANGED: In Spec file, changed prereq of elastix to 2.0.4-19

* Tue Mar 29 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-6
- FIXED: module addons_availables, the input search didnt work. 
  Now the searching is working fine. SVN Rev[2429]
- CHANGED: module addons, changed the title of the menu to 
  "Available". SVN Rev[2427][2428]

* Mon Feb 07 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-5
- CHANGED:  In Spec file add prerequiste elastix 2.0.4-9

* Mon Feb 07 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-4
- CHANGED:   In Spec add lines to support install or update 
  proccess by script.sql.
- DELETED:   Databases sqlite were removed to use the new format 
  to sql script for administer process install, update and delete
  SVN Rev[2332]
- ADD:  addons, agenda, reports. Add folders to contain sql 
  scrips to update, install or delete. SVN Rev[2321]

* Thu Feb 03 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-3
- CHANGED:  menu.xml to support new tag "permissions" where has
  all permissions of group per module and new attribute "desc" 
  into tag  "group" for add a description of group. 
  SVN Rev[2294][2299]

* Wed Jan 05 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-2
- FIXED:   Fixed message error in process to install where the 
  message error is "SQL error: table addons_cache already exists"
  SVN Rev[2217]

* Mon Dec 27 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-1
- FIXED: Addons: Remove unnecessary ampersand from foreach 
  iteration over SimpleXMLElement->data. This item changes from a 
  simple array to an iterator in PHP 5.2+ and causes a fatal 
  error. Should fix Elastix bug #659. SVN Rev[2157]

* Mon Dec 06 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-20
- CHANGED:  massive search and replace of HTML encodings with the
  actual characters. SVN Rev[2002]

* Wed Oct 27 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-19
- FIXED:    unset session variable elastix_user_permission to clean
  the session variable, it allow to load the new module installed 
  through addons. SVN Rev[1859]

* Mon Oct 18 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-18
- CHANGED:   Updated fr.lang SVN Rev[1825]

* Tue Oct 12 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-17
- ADDED:      New fa.lang file (Persian). SVN Rev[1793]

* Wed Aug 18 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-16
- CHANGED:    New message status was put in addons_availables to know what is the status of a download. Rev[1710]
- FIXED:      Message alert "error to install" in addons_availables was fixed. Rev[1710]

* Thu Aug 12 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-15
- FIXED: Also terminate an inactive yum shell after error conditions. Rev[1684]

* Sat Aug 07 2010 Bruno Macias V. <bmacias@palosanto.com> 2.0.0-14
- FIXED: Bad form closed in file installer.php

* Sat Aug 07 2010 Bruno Macias V. <bmacias@palosanto.com> 2.0.0-13
- FIXED: When exists a installations and the user has closed your browser, the status confirmation wasn't handler.

* Wed Jul 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-12
- CHANGED: Textfields and its names have been improved for being easier to understand. Rev[1640].  

* Wed Jul 21 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-11
- CHANGED: The Style was improved and the data information sent from web services was changed.

* Fri Jul 16 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-10
- ADDED: Implemented capability to store/retrieve arbitrary string. Intended for use with web interface to store update status with urlencode(serialize($var)).
- FIXED: refresh data base cache dont work. now the bug was fixed. 

* Wed Jul 14 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-9
- ADDED: flesh out support for updating and deleting addon modules
- ADDED: Implemented ability to track down which target addon failed due to missing dependency, and report which missing dependency caused the failure.
- CHANGED: Improved interface module addons availables for support report dependency caused the failure.

* Thu Jul 01 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-8
- CHANGED: Time for refresh session cache about addons available is 2 hours. 

* Mon Jun 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-7
- NEW:     Add table for caching the response from web services. This response about addons availables can be saved and it will be update whe session time for request is out.
- CHANGED: Control time for request to web services data, it allow do not call repeat times for request data.

* Thu Jun 17 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-6
- Now show the process install and different errors when there is a trouble

* Mon Jun 7 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-5
- Bug about the connection with web services is fixed, before when client do not get any connection with the web services the list of addons appear empty.
- New information about YUM install.
- Improve in send of the data, better organization

* Thu Apr 15 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-4
- Fixed bug dont install module developer and drive the session data install in database local addons.
- Improve the look module addons.

* Thu Mar 25 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-3
- solved problem where process YUM was always listening if there is a process to install. Now The daemon only turn on YUM for listening process and turn off when no process.

* Fri Mar 19 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-2
- Change url web services to webservice.elastix.org

* Tue Mar 16 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-1
- Initial version.
