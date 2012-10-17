%define modname security

Summary: Elastix Security 
Name:    elastix-%{modname}
Version: 3.0.0
Release: 1
License: GPL
Group:   Applications/System
Source0: %{modname}_%{version}-%{release}.tgz
#Source0: %{modname}_%{version}-6.tgz
BuildRoot: %{_tmppath}/%{name}-%{version}-root
BuildArch: noarch
Prereq: elastix-framework >= 3.0.0-1
Prereq: iptables
Requires: elastix-system
Requires: php-mcrypt
Requires: elastix-portknock

%description
Elastix Security

%prep
%setup -n %{modname}

%install
rm -rf $RPM_BUILD_ROOT

# Files provided by all Elastix modules
mkdir -p    $RPM_BUILD_ROOT%{_localstatedir}/www/html/
mkdir -p    $RPM_BUILD_ROOT%{_datadir}/elastix/privileged
mv modules/ $RPM_BUILD_ROOT%{_localstatedir}/www/html/
mv setup/usr/share/elastix/privileged/*  $RPM_BUILD_ROOT%{_datadir}/elastix/privileged
rmdir setup/usr/share/elastix/privileged

chmod +x setup/updateDatabase

# Crontab for portknock authorization cleanup
mkdir -p $RPM_BUILD_ROOT%{_sysconfdir}/cron.d/
cp setup/etc/cron.d/elastix-portknock.cron $RPM_BUILD_ROOT%{_sysconfdir}/cron.d/
chmod 644 $RPM_BUILD_ROOT%{_sysconfdir}/cron.d/elastix-portknock.cron

# Startup service for portknock
mkdir -p $RPM_BUILD_ROOT%{_sysconfdir}/rc.d/init.d/
cp setup/etc/rc.d/init.d/elastix-portknock $RPM_BUILD_ROOT%{_sysconfdir}/rc.d/init.d/
chmod 755 $RPM_BUILD_ROOT%{_sysconfdir}/rc.d/init.d/elastix-portknock

# Portknock-related utilities
mkdir -p $RPM_BUILD_ROOT%{_bindir}/
cp setup/usr/bin/elastix-portknock* $RPM_BUILD_ROOT%{_bindir}/
chmod 755 $RPM_BUILD_ROOT%{_bindir}/elastix-portknock*
rmdir setup/usr/bin

rmdir setup/usr/share/elastix setup/usr/share setup/usr

# The following folder should contain all the data that is required by the installer,
# that cannot be handled by RPM.
mkdir -p    $RPM_BUILD_ROOT%{_datadir}/elastix/module_installer/%{name}-%{version}-%{release}/
mv setup/   $RPM_BUILD_ROOT%{_datadir}/elastix/module_installer/%{name}-%{version}-%{release}/
mv menu.xml $RPM_BUILD_ROOT%{_datadir}/elastix/module_installer/%{name}-%{version}-%{release}/

%pre
mkdir -p %{_datadir}/elastix/module_installer/%{name}-%{version}-%{release}/
touch %{_datadir}/elastix/module_installer/%{name}-%{version}-%{release}/preversion_%{modname}.info
if [ $1 -eq 2 ]; then
    rpm -q --queryformat='%{VERSION}-%{RELEASE}' %{name} > %{_datadir}/elastix/module_installer/%{name}-%{version}-%{release}/preversion_%{modname}.info
fi

%post
pathModule="%{_datadir}/elastix/module_installer/%{name}-%{version}-%{release}"

# Run installer script to fix up ACLs and add module to Elastix menus.
elastix-menumerge $pathModule/menu.xml
pathSQLiteDB="%{_localstatedir}/www/db"
mkdir -p $pathSQLiteDB
preversion=`cat $pathModule/preversion_%{modname}.info`
rm $pathModule/preversion_%{modname}.info

if [ $1 -eq 1 ]; then #install
  # The installer database
    elastix-dbprocess "install" "$pathModule/setup/db"
elif [ $1 -eq 2 ]; then #update
   # The update database
      $pathModule/setup/checkFields "$preversion" "$pathModule"
      elastix-dbprocess "update"  "$pathModule/setup/db" "$preversion"
      $pathModule/setup/updateDatabase "$preversion"
fi

# The installer script expects to be in /tmp/new_module
mkdir -p /tmp/new_module/%{modname}
cp -r $pathModule/* /tmp/new_module/%{modname}/
chown -R asterisk.asterisk /tmp/new_module/%{modname}

php /tmp/new_module/%{modname}/setup/installer.php
rm -rf /tmp/new_module

%{_datadir}/elastix/privileged/anonymoussip --conddisable

# Install elastix-portknock as a service
chkconfig --add elastix-portknock
chkconfig --level 2345 elastix-portknock on

%clean
rm -rf $RPM_BUILD_ROOT

%preun
pathModule="%{_datadir}/elastix/module_installer/%{name}-%{version}-%{release}"

if [ $1 -eq 0 ] ; then # Validation for desinstall this rpm
  echo "Delete Security menus"
  elastix-menuremove "%{modname}"

  echo "Dump and delete %{name} databases"
  elastix-dbprocess "delete" "$pathModule/setup/db"
fi

%files
%defattr(-, root, root)
%{_localstatedir}/www/html/*
%{_datadir}/elastix/module_installer/*
%defattr(644, root, root)
%{_sysconfdir}/cron.d/elastix-portknock.cron
%defattr(0755, root, root)
%{_datadir}/elastix/privileged/*
%{_sysconfdir}/rc.d/init.d/elastix-portknock
%{_bindir}/elastix-portknock-cleanup
%{_bindir}/elastix-portknock-validate

%changelog
* Wed Oct 17 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- Framework,Modules: remove temporary file preversion_MODULE.info under 
  /usr/share/elastix/module_installer/MODULE_VERSION/ which otherwise prevents
  proper cleanup of /usr/share/elastix/module_installer/MODULE_VERSION/ on 
  RPM update. Part of the fix for Elastix bug #1398.
- Framework,Modules: switch as many files and directories as possible under
  /var/www/html to root.root instead of asterisk.asterisk. Partial fix for 
  Elastix bug #1399.
- Framework,Modules: clean up specfiles by removing directories under 
  /usr/share/elastix/module_installer/MODULE_VERSION/setup/ that wind up empty
  because all of their files get moved to other places.
  SVN Rev[4347]

* Thu Sep 20 2012 Luis Abarca <labarca@palosanto.com> 3.0.0-1
- CHANGED: security - Build/elastix-security.spec: Update specfile with latest
  SVN history. Changed version and release in specfile.
- CHANGED: In spec file changed Prereq elastix-framework to
  elastix-framework >= 3.0.0-1

* Thu Aug 09 2012 German Macas <gmacas@palosanto.com>
- FIXED: modules - antispam - festival - sec_advanced_setting - remote_smtp:
  Fixed graphic bug in ON/OFF Button
  SVN Rev[4102]

* Wed Aug 08 2012 German Macas <gmacas@palosanto.com>
- sec_rules - Fixed graphic bug in edition of New Rule of Firewall and improve
  design
  SVN Rev[4099]

* Fri Jul 27 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Port Knocking: trim padding of null bytes from end of plaintext.
  SVN Rev[4079]

* Fri Jun 29 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- ADDED: Implement Port Knocking support. This includes:
  - Two new modules: PortKnocking Interfaces, PortKnocking Users
  - New service elastix-portknock, requires package elastix-portknock
  - New dependency on php-mcrypt
  - New crontab job for authorization cleanup
  SVN Rev[4031]

* Mon Jun 25 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Define Ports, Audit, Weak Keys: Remove XSS vulnerability.
  SVN Rev[4010]

* Tue Jun 12 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Sec_Rules: Remove code that was copypasted from paloSantoNetwork, and
  reference the source directly. This allows the module to work properly with
  the fixes made for Fedora 17. Also, remove an unneeded reference to 
  paloSantoConfig. 

* Fri Apr 27 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-5
- CHANGED: Security - Build/elastix-security.spec: update specfile with latest
  SVN history. Changed release in specfile
- DELETED: modules - security: delete file db-delete not necesary.
  SVN Rev[3878]

* Fri Mar 30 2012 Bruno Macias <bmacias@palosanto.com> 2.3.0-4
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-5
- FiXED: Security - Sec_Rules: Fixed action changed order firewalls rules. 
  This problem appear by the new grid.
  SVN Rev[3801] 
- CHANGED: modules - firewall, se revierte los cambios del firewall activado 
  por omisión hasta mejorar el diseño y conjunto de reglas activas.
  SVN Rev[3799]

* Tue Mar 27 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-3
- CHANGED: modules - firewall rules: se activa el puerto 80, http
  SVN Rev[3788]

* Mon Mar 26 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-2
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-3
- FIXED: Security - sec_rules/index.php: Don't execute any action when give
  click in the rows to move the order of the firewalls rules
  bug: 1209
  Since: New theme elastix-neo
  SVN Rev[3777] [3776]
- CHANGED: Security - sec_accessaudit/index.php: Little better in show filters
  so don't appear the option x when is applied the default filter
  SVN Rev[3772]
- CHANGED: Security - /db/install/iptables/1_schema.sql: Changed the rules that
  will be activated by default
  SVN Rev[3767]


* Wed Mar 07 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-1
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-1
- CHANGED: sec_weak_keys index.php add control to applied filters
  SVN Rev[3722]
- CHANGED: sec_accessaudit index.php add control to applied filters
  SVN Rev[3721]
- CHANGED: sec_ports index.php add control to applied filters
  SVN Rev[3720]
- CHANGED: little change in file *.tpl to better the appearance the options
  inside the filter
  SVN Rev[3639]

* Wed Feb 01 2012 Rocio Mera <rmera@palosanto.com> 2.2.0-13
- CHANGED: file index.php to fixed the problem with the paged
  SVN Rev[3625]

* Mon Jan 30 2012 Alberto Santos <asantos@palosanto.com> 2.2.0-12
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-29
- CHANGED: to fixed the problem with the pagineo
  SVN Rev[3608]
- CHANGED: modules - sec_rules/index.php cambio menor en el mensaje
  de error en el modulo sec_rules
  SVN Rev[3586]

* Fri Jan 27 2012 Rocio Mera <rmera@palosanto.com> 2.2.0-11
- CHANGED: In spec file, changed prereq to elastix-framework >= 2.2.0-28
- CHANGED: modules - trunk/core/security/modules/sec_rules/index.php: 
  Se modifico el archivo index.php para mejorar apariencia debido a 
  nueva grilla. SVN Rev[3577].
- CHANGED: modules - images: icon image title was changed on some 
  modules. SVN Rev[3572].
- CHANGED: modules - icons: Se cambio de algunos módulos los iconos 
  que los representaba. SVN Rev[3563].
- CHANGED: Modules - Security: Added support for the new grid layout.
  SN Rev[3546].
- UPDATED: modules - *.tpl: Se elimino en los archivos .tpl de ciertos
  módulos que tenian una tabla demás en su diseño de filtro que 
  formaba parte de la grilla. SVN Rev[3541].

* Mon Dec 26 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-10
- FIXED: Modules - Security: In privileged file "anonymoussip" add 
  validation when asterisk services is shutdown, it only occur in a 
  proccess install of ISO because this script do a reload asterisk 
  and in that stage asterisk do not UP. SVN Rev[3485]

* Fri Nov 25 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-9
- CHANGED: In spec file, changed prereq to elastix-framework >= 2.2.0-18
- ADDED: Advanced Security Settings: use privileged script
  'anonymoussip' to enable/disable allowguest in sip_general_additional.conf
  SVN Rev[3401]
- ADDED: Advanced Security Settings: introduce new privileged script
  'anonymoussip' to enable/disable allowguest in sip_general_additional.conf
  SVN Rev[3398]
- CHANGED: Advanced Security Settings: report success or failure
  to modify all files in setadminpwd
  SVN Rev[3397]
- CHANGED: Advanced Security Settings: use privileged script 
  'setadminpwd' to reimplement administrator password reset
  SVN Rev[3396]
- FIXED: module sec_advanced_settings, the informative message
  was only displayed under theme elastixneo. Now it is displayed
  in all the themes
  SVN Rev[3395]
- ADDED: Advanced Security Settings: introduce new privileged script
  'setadminpwd' to set database passwords in various configuration files.
  Note: a privileged script is not technically necessary since all
  modified configuration files are owned by asterisk, but will be
  required if (when) the web interface is restored to run as httpd
  rather than asterisk.
  SVN Rev[3394]
- ADDED: update script sql that changes the name to "RTP" to the port
  10000:20000 and the name to "IAX2" to the port 4569
  SVN Rev[3362]

* Tue Nov 22 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-8
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-15
- FIXED: Define Ports: remove nested <form> tag
  SVN Rev[3278]
- FIXED: Firewall Rules: remove nested <form> tag
  SVN Rev[3277]

* Sat Oct 29 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-7
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-13

* Sat Oct 29 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-6
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-12
- CHANGED: Modules - Security: Changee label of modules Advance
  security settings to Advence
  SVN Rev[3222]
- CHANGED: module sec_rules, changed the color of fieldset border
  SVN Rev[3201]
- FIXED: module sec_advanced_settings, the feedback message was
  not displayed for theme elastixneo
  SVN Rev[3186]
- UPDATED: my extesion modules  templates files support new elastixneo theme
  SVN Rev[3159]

* Thu Oct 13 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-5
- FIXED: script privileged fwconfig, when flushin rules the file
  /etc/sysconfig/iptables is now blanked
  SVN Rev[3077]

* Thu Oct 06 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-4
- CHANGED: script updateDatabase, added the line "#!/usr/bin/php"
  at the beginning of the script
  SVN Rev[3049]
- CHANGED: script updateDatabase.php, this script will only be
  excecuted if the version is less than 2.2.0-4
  SVN Rev[3045]
- CHANGED: script fwconfig, now the source port and destination
  port have to be queried to the table port
  SVN Rev[3044]
- CHANGED: module sec_rules, changed the number of the ports
  by its name
  SVN Rev[3043]
- CHANGED: module sec_ports, now ports used in firewall rules
  can not be deleted
  SVN Rev[3042]
- CHANGED: added new script updateDatabase.php that changes the
  value of the port for its ids, also this change is made in
  script 1_schema.sql for new installations
  SVN Rev[3041]
- FIXED: module sec_ports, added an id of "filter_value" to the 
  filter text box, also the filter now looks for any coincidence
  that has the word entered in the filter
  SVN Rev[3031]

* Tue Sep 27 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-3
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-5
- CHANGED: changed the password "elastix456" of AMI to the 
  password set in /etc/elastix.conf
  SVN Rev[2995]

* Thu Sep 08 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-2
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-3
- CHANGED: module sec_ports, in view mode the asterisks and word
  required were removed
  SVN Rev[2952]
- ADDED: in sql script for installations, added the tftp port
  and added new rule to accept tftp traffic
  SVN Rev[2940]
- FIXED: incorrect order of hierarchy in updates scripts for
  database iptables
  SVN Rev[2897]

* Wed Aug 03 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-1
- DELETED: deleted sql script update for database iptables.db
  SVN Rev[2872]
- NEW: new scripts checkFields and compareVersion
  SVN Rev[2871]
- CHANGED: In Spec file, changed prereq elastix >= 2.2.0-1
- CHANGED: In Spec file, moved privileged files to path
  /usr/share/elastix/privileged

* Tue Jul 28 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-15
- CHANGED: Firewall Rules: make use of fwconfig helper for 
  flush/apply rules. SVN Rev[2785]
- CHANGED: Firewall Rules: fwconfig: use escapeshellarg() on every 
  value extracted from the database. SVN Rev[2784]
- ADDED: Firewall Rules: Introduce 'fwconfig' privileged helper. 
  This makes use of the elastix-helper framework introduced in 
  commit 2683. SVN Rev[2783]
- CHANGED: Security/Firewall Rules: (trivial) mark some methods 
  as private. SVN Rev[2779]

* Wed Jun 29 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-14
- FIXED: module sec_accessaudit, the exportation was only page by
  page. Now in the exportation you have the data of all pages
  SVN Rev[2766]
- FIXED: module sec_advanced_settings, added the id of the menu
  to ajax requests
  SVN Rev[2763]
- FIXED: module sec_rules, added the id of the menu in ajax requests
  SVN Rev[2762]

* Mon Jun 13 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-13
- NEW: new module sec_advanced_settings created
- CHANGED: In SPEC file changed prereq freepbx >= 2.8.1.1 and 
  elastix >= 2.0.4-24
- CHANGED: module sec_accessaudit, changed module name to "Audit".
  SVN Rev[2720]
- FIXED: module sec_rules, when a source port and destiny port 
  are entered the word "-p protocol" in the iptable rule is written 
  twice. Now that word its only written once. SVN Rev[2671]
- CHANGED: The split function of these modules was replaced by the 
  explode function due to that the split function was deprecated 
  since PHP 5.3.0. SVN Rev[2650]
- FIXED: module sec_rules, rules can not change order between pages. 
  Now the user can change the order of rules to other page. 
  SVN Rev[2630]

* Wed Apr 27 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-12
- CHANGED: module sec_rules, changed informative message according
  to bug #759
  SVN Rev[2524]
- CHANGED: menu.xml of security, changed 'FireWall Rules' to
  'Firewall Rules'
  SVN Rev[2523]
- CHANGED: file db.info, changed installation_force to ignore_backup
  SVN Rev[2493]
- CHANGED: In Spec file, changed prereq of elastix to 2.0.4-19

* Tue Mar 01 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-11
- CHANGED: In spec file changed prereq elastix >= 2.0.4-10
- FIXED: module sec_rules, changed the event from onClick to 
  onChange. SVN Rev[2364]
- CHANGED: module sec_rules, changed the translate or spelling 
  of some labels. SVN Rev[2363]
- CHANGED:  Change the way to organize the script.sql of databases
  SVN Rev[2334]

* Mon Feb 07 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-10
- CHANGED:  In Spec file add prerequiste elastix 2.0.4-9
- CHANGED:  Change the way to organize the script.sql of databases
  SVN Rev[2334]

* Thu Feb 03 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-9
- CHANGED:  menu.xml to support new tag "permissions" where has 
  all permissions of group per module and new attribute "desc" 
  into tag  "group" for add a description of group. 
  SVN Rev[2294][2299]
- CHANGED:  changed name and path of file.info to db.info. This
  file contains the database name of all db used for this rpm.
  SVN Rev[2296]

* Wed Feb 02 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-8
- CHANGED: Add new column "state" in table filter of iptable.db.
  SVN Rev[2292]
- ADD:     new field in the table filter and new rule was inserted 
  in the table filter. SVN Rev[2263]
- FIXED:   module sec_rules, new rule that allows yum and ssh.
  SVN Rev[2262]
- FIXED:   module sec_weak_keys, pagination did not work. Now the 
  pagination is working. SVN Rev[2260]

* Thu Jan 13 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-7
- CHANGED: module sec_rules, added new button desactivate Firewall
  SVN Rev[2239]
- CHANGED: module sec_rules, new images for the help and new icon 
  for the module. SVN Rev[2238]
- CHANGED: module sec_rules, the first time state interaction was
  improved. SVN Rev[2236]
- FIXED: module sec_rules, the problem of the last row was fixed
  SVN Rev[2231]

* Wed Jan 05 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-6
- CHANGED: module sec_weak_keys, changed the word key for secret 
  and new validations for security. SVN Rev[2221]

* Wed Jan 05 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-5
- ADDED: schema of database, new column in the table tmp_execute
  SVN Rev[2214]
- CHANGED: module sec_weak_keys, validation if the user is admin 
  or not for privileges. SVN Rev[2213]
- UPDATED: Module Rule Firewall, New Rule was added to accept
  IMAP traffic. SVN Rev[2210]
- FIXED: Module Access Audit, Fixed bug where do not showing 
  the correct amount of pages. SVN Rev[2208]

* Thu Dec 30 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-4
- CHANGED: module sec_rules, new method for validation of ip.
  SVN Rev[2194]
- FIXED: Module Security, New rule of firewall to accept 
  resolve DNS. SVN Rev[2193]

* Wed Dec 29 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-3
- CHANGED: Module Security, Change name of modules and join up 
  the modules "details port" and "rulers". SVN Rev[2178]

* Tue Dec 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-2
- CHANGE: module sec_rules, add a line to create the file 
  /etc/sysconfig/iptabl. SVN Rev[2172]

* Wed Dec 22 2010 Bruno Macias V. <bmacias@palosanto.com> 2.0.4-1
- Initial version.
