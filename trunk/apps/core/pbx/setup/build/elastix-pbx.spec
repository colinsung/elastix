%define modname pbx 

Summary: Elastix Module PBX 
Name:    elastix-%{modname}
Version: 2.3.0
Release: 10
License: GPL
Group:   Applications/System
Source0: %{modname}_%{version}-%{release}.tgz
#Source0: %{modname}_%{version}-20.tgz
BuildRoot: %{_tmppath}/%{name}-%{version}-root
BuildArch: noarch
Prereq: elastix-framework >= 2.3.0-9
Prereq: elastix-my_extension >= 2.0.4-5
Prereq: freePBX >= 2.8.1-12
Prereq: elastix-system >= 2.2.0-18
Prereq: tftp-server, vsftpd
Prereq: asterisk >= 1.8
Requires: festival >= 1.95

%description
Elastix Module PBX

%prep


%setup -n %{modname}

%install
rm -rf $RPM_BUILD_ROOT

# Asterisk files
mkdir -p $RPM_BUILD_ROOT/var/lib/asterisk/agi-bin
mkdir -p $RPM_BUILD_ROOT/var/lib/asterisk/mohmp3

mkdir -p $RPM_BUILD_ROOT/etc/cron.daily

# ** /bin path ** #
mkdir -p $RPM_BUILD_ROOT/bin

# Files provided by all Elastix modules
mkdir -p    $RPM_BUILD_ROOT/var/www/html/
mv modules/ $RPM_BUILD_ROOT/var/www/html/

# ** files ftp ** #
#mkdir -p $RPM_BUILD_ROOT/var/ftp/config

# ** /tftpboot path ** #
mkdir -p $RPM_BUILD_ROOT/tftpboot

# ** /asterisk path ** #
mkdir -p $RPM_BUILD_ROOT/etc/asterisk/

# ** service festival ** #
mkdir -p $RPM_BUILD_ROOT/etc/init.d/
mkdir -p $RPM_BUILD_ROOT/var/log/festival/

# The following folder should contain all the data that is required by the installer,
# that cannot be handled by RPM.
mkdir -p      $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
mkdir -p      $RPM_BUILD_ROOT/usr/share/elastix/privileged/

# crons config
mv setup/etc/cron.daily/asterisk_cleanup      $RPM_BUILD_ROOT/etc/cron.daily/
chmod 755 $RPM_BUILD_ROOT/etc/cron.daily/*

# ** asterisk.reload file ** #
mv setup/bin/asterisk.reload                  $RPM_BUILD_ROOT/bin/
chmod 755 $RPM_BUILD_ROOT/bin/asterisk.reload

# ** files asterisk for agi-bin and mohmp3 ** #
mv setup/asterisk/agi-bin/*                   $RPM_BUILD_ROOT/var/lib/asterisk/agi-bin/
chmod 755 $RPM_BUILD_ROOT/var/lib/asterisk/agi-bin/*
mv setup/asterisk/mohmp3/*                    $RPM_BUILD_ROOT/var/lib/asterisk/mohmp3/

# Moviendo archivos festival y sip_notify_custom_elastix.conf
chmod +x setup/etc/asterisk/sip_notify_custom_elastix.conf
chmod +x setup/etc/init.d/festival
mv setup/etc/asterisk/sip_notify_custom_elastix.conf      $RPM_BUILD_ROOT/etc/asterisk/
mv setup/etc/init.d/festival                              $RPM_BUILD_ROOT/etc/init.d/
mv setup/usr/share/elastix/privileged/*                   $RPM_BUILD_ROOT/usr/share/elastix/privileged/

# Archivos tftp and ftp
mv setup/etc/xinetd.d/tftp                     $RPM_BUILD_ROOT/usr/share/elastix/
#mv setup/etc/vsftpd/vsftpd.conf               $RPM_BUILD_ROOT/usr/share/elastix/
#mv setup/etc/vsftpd.user_list                 $RPM_BUILD_ROOT/etc/

# ** files tftpboot for endpoints configurator ** #
unzip setup/tftpboot/P0S3-08-8-00.zip  -d     $RPM_BUILD_ROOT/tftpboot/
#mv setup/tftpboot/GS_CFG_GEN.tar.gz           $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
#tar -xvzf $RPM_BUILD_DIR/elastix/additionals/tftpboot/GS_CFG_GEN.tar.gz -C $RPM_BUILD_ROOT/tftpboot/ # Da algunos mensajes de warning, esto se puede obviar.
mv setup/tftpboot/*                           $RPM_BUILD_ROOT/tftpboot/

mv setup/     $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
mv menu.xml   $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/

#chown asterisk.asterisk $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/setup/extensions_override_elastix.conf


%pre
#Para migrar monitor
touch /tmp/migration_version_monitor.info
rpm -q --queryformat='%{VERSION}\n%{RELEASE}' elastix > /tmp/migration_version_monitor.info

# TODO: TAREA DE POST-INSTALACIÓN
#useradd -d /var/ftp -M -s /sbin/nologin ftpuser

# Try to fix mess left behind by previous packages.
if [ -e /etc/vsftpd.user_list ] ; then
    echo "   NOTICE: broken vsftpd detected, will try to fix..."
    cp /etc/vsftpd.user_list /tmp/
fi

mkdir -p /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
touch /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/preversion_%{modname}.info
if [ $1 -eq 2 ]; then
    rpm -q --queryformat='%{VERSION}-%{RELEASE}' %{name} > /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/preversion_%{modname}.info
fi

%post
# Unpack tarball with binary files.
#tar -xvzf /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/GS_CFG_GEN.tar.gz -C /tftpboot
# Replace path java for script encode.sh
#sed -i -e "s,JAVA_HOME=[0-9a-zA-Z._-/]*,JAVA_HOME=/opt/openfire/jre,g" /tftpboot/GS_CFG_GEN/bin/encode.sh
#sed -i -e "s,JAVA_HOME=/usr/java/j2sdk1.4.2_07,JAVA_HOME=/opt/openfire/jre,g" /tftpboot/GS_CFG_GEN/bin/encode.sh
#sed -i -e "s,GAPSLITE_HOME=/usr/local/src/GS_CFG_GEN,GAPSLITE_HOME=/tftpboot/GS_CFG_GEN,g" /tftpboot/GS_CFG_GEN/bin/encode.sh

# Tareas de TFTP
chmod 777 /tftpboot/

# TODO: TAREA DE POST-INSTALACIÓN
# Tareas de VSFTPD
#chkconfig --level 2345 vsftpd on
#chmod 777 /var/ftp/config

# TODO: TAREA DE POST-INSTALACIÓN
# Reemplazo archivos de otros paquetes: tftp, vsftp
cat /usr/share/elastix/tftp   > /etc/xinetd.d/tftp
#cat /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/etc/vsftpd/vsftpd.conf > /etc/vsftpd/vsftpd.conf


######### Para ejecucion del migrationFilesMonitor.php ##############

#/usr/share/elastix/migration_version_monitor.info
#obtener la primera linea que contiene la version

vers=`sed -n '1p' "/tmp/migration_version_monitor.info"`
if [ "$vers" = "1.6.2" ]; then
  rels=`sed -n '2p' "/tmp/migration_version_monitor.info"`
  if [ $rels -le 13 ]; then # si el release es menor o igual a 13 entonces ejecuto el script

    echo "Executing process migration audio files Monitor"
    chmod +x /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/setup/migrationFilesMonitor.php
    php /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/setup/migrationFilesMonitor.php
  fi
fi
rm -rf /tmp/migration_version_monitor.info
###################################################################

varwriter=0

if [ -f "/etc/asterisk/extensions_override_freepbx.conf" ]; then
    echo "File extensions_override_freepbx.conf in asterisk exits, verifying macro record-enable and hangupcall exists..."
    grep "#include extensions_override_elastix.conf" /etc/asterisk/extensions_override_freepbx.conf &>/dev/null
    res=$?
    if [ $res -eq 1 ]; then #macro record-enable not exists
	echo "#include extensions_override_elastix.conf" > /tmp/ext_over_freepbx.conf
        cat /etc/asterisk/extensions_override_freepbx.conf >> /tmp/ext_over_freepbx.conf
        cat /tmp/ext_over_freepbx.conf > /etc/asterisk/extensions_override_freepbx.conf
	rm -rf /tmp/ext_over_freepbx.conf
        echo "macros elastix was written."
    fi
else
    echo "File extensions_override_freepbx.conf in asterisk not exits, copying include macros elastix..."
    touch /etc/asterisk/extensions_override_freepbx.conf
    echo "#include extensions_override_elastix.conf" > /etc/asterisk/extensions_override_freepbx.conf
fi

# verifico si se incluye a sip_notify_custom_elastix.conf
if [ -f "/etc/asterisk/sip_notify_custom.conf" ]; then
    echo "/etc/asterisk/sip_notify_custom.conf exists, verifying the inclusion of sip_notify_custom_elastix.conf"
    grep "#include sip_notify_custom_elastix.conf" /etc/asterisk/sip_notify_custom.conf &> /dev/null
    if [ $? -eq 1 ]; then
	echo "including sip_notify_custom_elastix.conf..."
	echo "#include sip_notify_custom_elastix.conf" > /tmp/custom_elastix.conf
	cat /etc/asterisk/sip_notify_custom.conf >> /tmp/custom_elastix.conf
	cat /tmp/custom_elastix.conf > /etc/asterisk/sip_notify_custom.conf
	rm -rf /tmp/custom_elastix.conf
    else
	echo "sip_notify_custom_elastix.conf is already included"
    fi
else
    echo "creating file /etc/asterisk/sip_notify_custom.conf"
    touch /etc/asterisk/sip_notify_custom.conf
    echo "#include sip_notify_custom_elastix.conf" > /etc/asterisk/sip_notify_custom.conf
fi

varwriter=1
mv /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/setup/extensions_override_elastix.conf /etc/asterisk/
chown -R asterisk.asterisk /etc/asterisk

if [ $varwriter -eq 1  ]; then
    service asterisk status &>/dev/null
    res2=$?
    if [ $res2 -eq 0  ]; then #service is up
         service asterisk reload
    fi
fi

pathModule="/usr/share/elastix/module_installer/%{name}-%{version}-%{release}"
# Run installer script to fix up ACLs and add module to Elastix menus.
elastix-menumerge /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/menu.xml

pathSQLiteDB="/var/www/db"
mkdir -p $pathSQLiteDB
preversion=`cat $pathModule/preversion_%{modname}.info`

if [ $1 -eq 1 ]; then #install
  # The installer database
   elastix-dbprocess "install" "/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/setup/db"
elif [ $1 -eq 2 ]; then #update
  # The installer database
   elastix-dbprocess "update"  "$pathModule/setup/db" "$preversion"
fi

#verificando si existe el menu en pbx
path="/var/www/db/acl.db"
path2="/var/www/db/menu.db"
id_menu="control_panel"

#obtenemos el id del recurso (EOP)
res=`sqlite3 $path "select id from acl_resource  where name='control_panel'"`

#obtenemos el id del grupo operador
opid=`sqlite3 $path "select id from acl_group  where name='Operator'"`

if [ $res ]; then #debe de existir el recurso EOP
   if [ $opid ]; then #debe de existir el grupo operador
      val=`sqlite3 $path "select * from acl_group_permission where id_group=$opid and id_resource=$res"`
      if [ -z $val ]; then #se pregunta si existe el permiso de EOP para el grupo Operador
         echo "updating group Operator with permissions in Control Panel Module"
	 `sqlite3 $path "insert into acl_group_permission(id_action, id_group, id_resource) values(1,$opid,$res)"`
      fi
   fi
fi

# The installer script expects to be in /tmp/new_module
mkdir -p /tmp/new_module/%{modname}
cp -r /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/* /tmp/new_module/%{modname}/
chown -R asterisk.asterisk /tmp/new_module/%{modname}

php /tmp/new_module/%{modname}/setup/installer.php
rm -rf /tmp/new_module

# Detect need to fix up vsftpd configuration
if [ -e /tmp/vsftpd.user_list ] ; then
    echo "   NOTICE: fixing up vsftpd configuration..."
    # userlist_deny=NO
    sed --in-place "s,userlist_deny=NO,#userlist_deny=NO,g" /etc/vsftpd/vsftpd.conf
    rm -f /tmp/vsftpd.user_list
fi


%clean
rm -rf $RPM_BUILD_ROOT

%preun
if [ $1 -eq 0 ] ; then # Validation for desinstall this rpm; delete
pathModule="/usr/share/elastix/module_installer/%{name}-%{version}-%{release}"
  echo "Delete System menus"
  elastix-menuremove "pbxconfig"

  echo "Dump and delete %{name} databases"
  elastix-dbprocess "delete" "$pathModule/setup/db"
fi

%files
%defattr(-, asterisk, asterisk)
%{_localstatedir}/www/html/*
/usr/share/elastix/module_installer/*
/etc/asterisk/sip_notify_custom_elastix.conf
/var/lib/asterisk/*
/var/lib/asterisk/agi-bin
/var/log/festival
/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/setup/extensions_override_elastix.conf
%defattr(-, root, root)
/tftpboot/*
/usr/share/elastix/tftp
%defattr(755, root, root)
/etc/init.d/festival
/bin/asterisk.reload
/usr/share/elastix/privileged/*
/var/lib/asterisk/agi-bin/*
/etc/cron.daily/asterisk_cleanup

%changelog
* Tue Jul 03 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Endpoint Batch: Properly figure out network mask for local network 
  instead of hardcoding a /24 network mask. SVN Rev[4037]

* Thu Jun 28 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Endpoint Configurator: Remove stray print_r.

* Mon Jun 25 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Conference: Remove XSS vulnerability.

* Tue Jun 19 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- CHANGED: Endpoint Configurator: Reimplement Grandstream configuration encoder
  as a pure PHP method. This allows the package to drop the Java-based encoder,
  which in turn allows the package to drop the openfire dependency.
- CHANGED: Endpoint Configurator: modify listmacip so that it can stream output
  from nmap as it is generated.

* Tue Jun 12 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Endpoint Configurator: Properly figure out network mask for local 
  network instead of hardcoding a /24 network mask.
- FIXED: Endpoint Configurator: Relax pattern matching in listmacip script to
  account for differences in output format in nmap from CentOS to Fedora 17.
- FIXED: Endpoint Configurator: Use ip addr show instead of ifconfig to get
  size of network mask for endpoint scan. Required for compatibility with 
  Fedora 17.

* Tue Jun 05 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: installer.php: stop leaving temporary file /tmp/trunk_dump.sql around 
  after install or update.
- FIXED: installer.php: stop leaving temporary file /var/www/db/trunk-pbx.db
  around on most install/update scenarios. 

* Mon Jun 04 2012 Alex Villacis Lasso <a_villacis@palosanto.com> 2.3.0-10
- FIXED: Changed specfile so that several files are explicitly set as 
  executable. Fixes Elastix bugs #1291, #1292.

* Fri Jun 01 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: On-demand recording must not use MIXMON_FORMAT. The format for
  recording is TOUCH_MONITOR_FORMAT, if defined, or wav if not defined.
- CHANGED: Use ASTSPOOLDIR instead of hardcoded /var/spool/asterisk.

* Mon May 28 2012 Sergio Broncano <sbroncano@palosanto.com> 2.3.0-9
- CHANGED: MODULES - PBX/EXTENSION_BATCH: The following fields were added 
  callgroup, pickupgroup, disallow, allow, deny, permit, Record Incoming, 
  Outgoing Record in extensiones.csv file to upload and download. 
  SVN Rev[3940]

* Mon May 07 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-8
- UPDATED: UPDATED in specfile to release 8

* Thu May 03 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Conference: Check the context variable MEETME_ACTUAL_RECORDINGFILE 
  alongside MEETME_RECORDINGFILE in order to decide whether a MeetMe recording
  exists. Before this check, CBMySQL conferences end up marking a recording as
  available in Monitoring report when no recording actually exists.
  FIXED: Conference: Check that MEETME_ACTUAL_RECORDINGFILE with MIXMON_FORMAT
  extension exists. If not, fallback to assuming .wav as file extension. Before
  this check, CBMySQL conference recordings are not downloadable if
  MIXMON_FORMAT is something other than .wav .
  SVN Rev[3926].

* Wed May 02 2012 Rocio Mera <rmera@palosanto> 2.3.0-7
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-9

* Wed May 02 2012 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: Conference: Rework several conference manipulation functions so that
  they use SQL parameters. Fixes Elastix bug #1256.
  FIXED: Conference: Reorder evaluation of actions so that kicking and muting
  participants actually works. Fixes Elastix bug #1245.
  SVN Rev[3916].
- FIXED: Conference: Validation of type "integer" for HH:MM is unimplemented.
  Use "numeric" instead.
  SVN Rev[3914].

* Fri Apr 27 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-6
- CHANGED: Addons - Build/elastix-addons.spec: update specfile with latest
  SVN history. Changed release in specfile
- FIXED: Monitoring: queue recording file names are written with full absolute
  path in DB, so a LIKE operator is required to select on the file name.
  Rewrite recording file download to add more checks. Part of fix for Elastix
  bug #1225.
  SVN Rev[3873]
- FIXED: Batch of Extensions: voicemail password (if any) should be
  numeric-only. Part of the fix for Elastix bug #1238.
  SVN Rev[3871]
- FIXED: PBX Admin: add enough of the FreePBX validation javascripts so that
  field validations work. Part of the fix for Elastix bug #1238.
  SVN Rev[3870]
- ADDED: Build - SPEC's: The spec files were added to the corresponding modules
  and the framework.
  SVN Rev[3854]
  SVN Rev[3836]
- FIXED: PBX - Festival: Fixed problem when chance status to festival. Bug 1219
  SVN Rev[3814]

* Mon Apr 02 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-5
- FIXED: PBX - Festival: Fixed problem when chance status to festival. Bug 1219
  SVN Rev[3814]

* Fri Mar 30 2012 Bruno Macias <bmacias@palosanto.com> 2.3.0-4
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-5
- CHANGED: Control Panel: sqlite query reports SELECT X.COLUMN as 'X.COLUMN' in
  CentOS and 'COLUMN' in Fedora. Query needs to explicitly set the column name
  and perform conversion to the expected format.
  SVN Rev[3806]
- FIXED: modules - SQLs DB: se quita SQL redundante de alter table y nuevos 
  registros, esto causaba un error leve en la instalación de el/los modulos.
  SVN Rev[3797]

* Mon Mar 26 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-3
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-3
- CHANGED: In spec file, changed prereq freePBX >= 2.8.1-12
- CHANGED: modules/control_panel: Se define auto como propiedad de altura a las
  areas.
  SVN Rev[3779]
- CHANGED: PBX - Monitoring little change in index.php to fix problem appear
  the 'X' option in whose filters that are always active
  SVN Rev[3757]
- CHANGED: PBX - Voicemail index.php change in index.php to fix problem
  coulnd't be the voicemail in the elastix web interface
  SVN Rev[3756]

* Fri Mar 09 2012 Alberto Santos <asantos@palosanto.com> 2.3.0-2
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-2
- CHANGED: PBX Admin: bail out if unable to connect to Asterisk AMI.
  SVN Rev[3727]

* Wed Mar 07 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-1
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-1
- FIXED: modules - faxlist: Se corrige bug de pagineo en el modulo de faxlist.
  Tambien se definen correctamente ciertas traducciones.
  SVN Rev[3714]
- CHANGED: voipprovider index.php add control to applied filters
  SVN Rev[3706]
- CHANGED: file_editor index.php add control to applied filters
  SVN Rev[3705]
- CHANGED: conference index.php add control to applied filters
  SVN Rev[3704]
- CHANGED: voicemail index.php add control to applied filters
  SVN Rev[3703]
- CHANGED: voicemail index.php add control to applied filters
  SVN Rev[3702]
- CHANGED: endpoint_configurator index.php change to put new function outside
  of filter
  SVN Rev[3685]
- FIXED: Conference: honor new parameter isUserAdministratorGroup in
  listConferences(). Requires elastix-conferenceroom >= 2.0.0.
  SVN Rev[3680]
- FIXED: PBX - Monitoring: sometimes blind transfer results in two or more CDRs
  with the same uniqueid. When recording calls, it is necessary to specify the
  distinct call filename in order to have access to all portions of the
  recording.
  SVN Rev[3678]
- UPDATED: modules - hardware_detector: Se define ancho de la tabla que formar
  parte de un puerto, en chrome se mostraba mal los puertos.
  SVN Rev[3663]
- CHANGED: pbx control_panel Now the max numbers of columns is 4, modifcations
  in index.php. Add the file 3_2.2.0-26_2.2.0-27.sql in db/update to change
  take efect in updating
  SVN Rev[3651]
- CHANGED: little change in file *.tpl to better the appearance the options
  inside the filter
  SVN Rev[3639]
- CHANGED: modules - extension_batch/themes: The incorrect positioning of
  Dowload link is now corrected.
  SVN Rev[3631]
- FIXED: Modules - Monitoring: Bugs about cannot listen on the web in
  google-crone the recording
  * Bug:0001085
  * Introduced by:
  * Since: Development Monitoring Module
  SVN Rev[3630]
- FIXED: Modules - Monitoring: Bugs about cannot listen on the web the
  recording
  * Bug:0001085
  * Introduced by:
  SVN Rev[3629]


* Wed Feb 1 2012 Rocio Mera <rmera@palosanto.com> 2.2.0-26
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-30
- CHANGED: file index.php to fixed the problem with the paged 
  to show callers of conference. SVN Rev[3623].
- CHANGED: file index.php to fixed the problem with the paged
  SVN Rev[3620].
- CHANGED: file index.php to fixed the problem with the paged.
  SVN Rev[3617]. SVN Rev[3616]. SVN Rev[3610]. 

* Mon Jan 30 2012 Alberto Santos <asantos@palosanto.com> 2.2.0-25
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-29
- CHANGED: Changed the word 'Apply Filter' by 'More Option'
  SVN Rev[3601]

* Sat Jan 28 2012 Rocio Mera <rmera@palosanto.com> 2.2.0-24
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-28
- CHANGED: Added support for the new grid design. SVN Rev[3576].
- CHANGED: modules - images: icon image title was changed on some 
  modules. SVN Rev[3572].
- CHANGED: modules - trunk/core/pbx/modules/monitoring/index.php: 
  Se modifico los archivos index.php para corregir problema con 
  botondeleted. SVN Rev[3570]. SVN Rev[3568].
- CHANGED: modules - icons: Se cambio de algunos módulos los iconos
  que los representaba. SVN Rev[3563].
- CHANGED: modules - * : Cambios en ciertos mòdulos que usan grilla 
  para mostrar ciertas opciones fuera del filtro, esto debido al 
  diseño del nuevo filtro. SVN Rev[3549].
- CHANGED: Modules - PBX: Added support for the new grid layout.
  SVN Rev[3548].
- UPDATED: modules - *.tpl: Se elimino en los archivos .tpl de 
  ciertos módulos que tenian una tabla demás en su diseño de filtro
  que formaba parte de la grilla. SVN Rev[3541].


* Thu Jan 12 2012 Alberto Santos <asantos@palosanto.com> 2.2.0-23
- ADDED: In spec file, added prereq asterisk >= 1.8
- CHANGED: In spec file, changed prereq elastix-system >= 2.2.0-18
- CHANGED: In spec file, changed prereq freePBX >= 2.8.1-11
- FIXED: modules control_panel, when the button reload is pressed
  all the boxes are displayed twice. This is fixed by doing an
  element.empty().append() where element is the container.
  FIXED: modules control_panel, the destination call was not
  displayed and when the page is refreshed all the times are reset
  to 3. This bug happens due to the migration to asterisk 1.8 where
  the command "core show channels concise" shows in different
  positions the time and destination of the call.
  SVN Rev[3524]
- CHANGED: modules endpoint_configurator, the VoIP server address
  is now the network that belongs to the endpoint's network address
  SVN Rev[3523]
- CHANGED: modules endpoint_configurator, now the network
  configuration is not changed in atcom telephones
  SVN Rev[3511]
- ADDED: added new update script for enpoint.db database, this
  scripts adds new atcom models "AT610" and "AT640" also changes
  old atcom model names to "AT620", "AT530" and "AT320"
  SVN Rev[3508]
- ADDED: modules endpoint_configurator, added new atcom models
  "AT610" and "AT640" also changed the name of old atcom models
  to "AT320", "AT530" and "AT620"
  SVN Rev[3507]

* Tue Jan 03 2012 Alberto Santos <asantos@palosanto.com> 2.2.0-22
- CHANGED: modules - pbx/setup/db: Schema de instalación de la
  base meetme, fue modificado para que no asigne una contraseña
  al definir el GRANT de permisos a la base meetme que sea
  administrable por el usuario asteriskuser
  SVN Rev[3499]

* Fri Dec 30 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-21
- CHANGED: In spec file, removed the creation of user asterisk,
  that is handled by elastix-framework

* Thu Dec 29 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-20
- CHANGED: In spec file, changed prereq elastix-framework >= 2.2.0-24
- CHANGED: In spec file, now in this spec is handled everything related
  with asterisk and not any more on framework
- CHANGED: changed everything to do with asterisk from the framework
  to elastix-pbx
  SVN Rev[3495]

* Tue Dec 20 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-19
- CHANGED: In spec file, changed prereq to elastix-framework >= 2.2.0-23
- FIXED: When export a csv file extensions, not export well voicemails fields, 
  the problem was because the path was not sent to find if the extension had 
  a voicemail active.. SVN Rev[3458]

* Thu Dec 08 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-18
- CHANGED: In spec file, changed prereq to elastix-framework >= 2.2.0-21
- FIXED: Festival: Use 'pidof' instead of 'service festival status' 
  to work around https://bugzilla.redhat.com/show_bug.cgi?id=684881
  SVN Rev[3431]

* Fri Nov 25 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-17
- CHANGED: In spec file, changed prereq to elastix-framework >= 2.2.0-18
- CHANGED: Recordings: use load_language_module instead of handcoding
  i18n loading
  FIXED: Recordings: fixed forgotten i18n string change in
  Spanish translation
  SVN Rev[3399]
- FIXED: module festival, informative message was only displayed
  for theme elastixneo. Now it is displayed for all the themes
  SVN Rev[3393]
- CHANGED: Festival: use privileged script 'festival' to reimplement
  festival server activation
  SVN Rev[3392]
- CHANGED: Festival: no need to remove festival service from chkconfig.
  SVN Rev[3391]
- CHANGED: Festival: check for non-existent festival.scm file
  SVN Rev[3390]
- ADDED: Festival: introduce new privileged script 'festival' to
  enable/disable Festival and to add required configuration.
  SVN Rev[3389]
- CHANGED: Festival: sudo is not required for querying festival
  server status
  SVN Rev[3388]
- FIXED: PBX Admin: freePBX modules always need type=setup defined,
  so define if display is not defined
  SVN Rev[3387]
- FIXED: PBX Admin: all freePBX module links need a type=setup
  GET parameter. Partially fixes PHP Notice:  Undefined index:
  1 in /var/www/html/admin/modules/fax/functions.inc.php on line 389
  SVN Rev[3386]
- FIXED: PBX Admin: assign instead of concatenate to $htmlFPBX.
  Fixes PHP Notice:  Undefined variable: htmlFPBX in
  /var/www/html/modules/pbxadmin/libs/contentFreePBX.php on line 492
  SVN Rev[3385]
- FIXED: PBX Admin: $amp_conf_defaults must be declared as global
  BEFORE admin/functions.inc.php, fixes PHP Warning:  Invalid
  argument supplied for foreach() in /var/www/html/admin/functions.inc.php
  on line 782
  SVN Rev[3384]
- CHANGED: Endpoint Configurator: use privileged script 'listmacip'
  to reimplement endpoint mapping
  SVN Rev[3382]
- CHANGED: Endpoint Configurator: privileged script 'listmacip'
  needs to report netcard vendor description too.
  SVN Rev[3381]
- ADDED: Endpoint Configurator: introduce new privileged script
 'listmacip' to map out available IPs and MACs in a network.
  This removes the need to allow full access to nmap via sudo.
  SVN Rev[3380]
- CHANGED: Endpoint Configurator: remove no-longer-necessary
  sudo chmod around invocation of encoder for GrandStream configurator.
  SVN Rev[3379]

* Wed Nov 23 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-16
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-17
- FIXED: module voicemail, wrong concatenation of variable 
  $contenidoModulo, consecuence of this the filter is showed twice
  SVN Rev[3353]

* Tue Nov 22 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-15
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-15
- FIXED: Voicemail: remove nested <form> tag. SVN Rev[3270]
- CHANGED: module endpoint_configurator, extensions showed 
  in field "Current Extension" are unregistered when the 
  button set is pressed. SVN Rev[3267]
- CHANGED: module endpoint_configurator, changed width and 
  align in input for discovering endpoints. SVN Rev[3263]

* Tue Nov 01 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-14
- CHANGED: In spec file, changed prereq freePBX >= 2.8.1-7
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-14
- CHANGED: module pbxadmin, was increased the width of warning
  message in option "Unembedded FreePBX"
  SVN Rev[3249]

* Sat Oct 29 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-13
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-13

* Sat Oct 29 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-12
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-12
- CHANGED: module pbxadmin, added a margin-top negative to the
  informative message in Unembedded FreePBX
  SVN Rev[3229]
- CHANGED: module control_panel, added a border-radius to the style
  SVN Rev[3228]
- CHANGED: module voipprovider, changed the color of fieldset border and title
  SVN Rev[3199]
- FIXED: module festival, messages was not displaying for theme elastixneo
  SVN Rev[3185]
- CHANGED: module voipprovider, the module title is now handled by the framework
  SVN Rev[3162]
- CHANGED: module recordings, the module title is now handled by the framework
  SVN Rev[3160]
- CHANGED: module text_to_wav, the module title is now handled by the framework
  SVN Rev[3158]
- CHANGED: module file_editor, the module title is now handled by the framework
  SVN Rev[3157]
- CHANGED: module asterisk_cli, the module title is now handled by the framework
  SVN Rev[3155]
- CHANGED: module extensions_batch, the module title is now handled by the framework
  SVN Rev[3153]
- CHANGED: module conference, the module title is now handled by the framework
  SVN Rev[3151]
- CHANGED: module voicemail, the module title is now handled by the framework
  SVN Rev[3150]
- CHANGED: module pbxadmin, changed the module title to "PBX Configuration"
  SVN Rev[3149]
- CHANGED: module pbxadmin, added a title to the module pbxadmin
  SVN Rev[3147]

* Mon Oct 17 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-11
- CHANGED: module endpoint_configurator, when a patton does not 
  have 2 ethernet ports, the WAN options are not displayed.
  SVN Rev[3087]
- CHANGED: module recordings, added information. SVN Rev[3082]

* Thu Oct 13 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-10
- CHANGED: module endpoint_configurator, added asterisks in some
  required fields
  SVN Rev[3081]
- CHANGED: module endpoint_configurator, in case an error occurs
  and a file can not be created, a message is showed
  SVN Rev[3078]

* Fri Oct 07 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-9
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-8
- FIXED: module conference, added an id of "filter_value" to the
  filter text box
  SVN Rev[3035]

* Wed Sep 28 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-8
- FIXED: module extensions_batch, wrong name of button, changed
  "CVS" to "CSV"
  SVN Rev[3006]
- FIXED: module extensions_batch, only if the field "Direct DID"
  is entered, an inbound route is created
  SVN Rev[3005]

* Tue Sep 27 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-7
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-5
- CHANGED: changed the password "elastix456" of AMI to the
  password set in /etc/elastix.conf
  SVN Rev[2995]

* Thu Sep 22 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-6
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-4
- ADDED: module endpoint_configurator, added the option for
  patton configuration
  SVN Rev[2985]
- ADDED: added script sql for database endpoint, it creates 
  a new table called settings_by_country and added the vendor Patton
  SVN Rev[2984]
- CHANGED: Conference: add new method required for verification 
  of date range. Part of fix for Elastix bug #937.
  SVN Rev[2983]
- FIXED: Embedded FreePBX: include jquery.toggleval if available,
  to fix some javascript errors.
  SVN Rev[2981]
- FIXED: Elastix Operator Panel: IE6 through IE8 deal incorrectly
  with nested draggables, as detailed in http://bugs.jqueryui.com/ticket/4333.
  Applied workaround suggested in bug report.
  SVN Rev[2980]
- FIXED: Elastix Operator Panel: fix incorrect regular expression 
  that missed extension names with dashes.
  SVN Rev[2979]
- CHANGED: Elastix Operator Panel: remove comment and trailing comma
  that trigger syntax error in IE6. Part of fix for Elastix bug #938.
  SVN Rev[2978]
- CHANGED: Elastix Operator Panel: use jQuery methods instead of
  innerHTML to insert table information. Part of fix for Elastix bug #938.
  SVN Rev[2977]
- CHANGED: Elastix Operator Panel: use DOM instead of innerHTML
  to insert loading animation. Part of fix for Elastix bug #938.
  SVN Rev[2976]
- FIXED: Control Panel: check for support of DOMParser and fall back
  to IE-specific code if not supported. Partial fix for Elastix bug #938.
  SVN Rev[2971]
- CHANGED: module text_to_wav, deleted unnecessary asterisks
  SVN Rev[2962]
- CHANGED: module extensions_batch, deleted unnecessary asterisks
  SVN Rev[2961]
- CHANGED: module monitoring, deleted unnecessary asterisks
  SVN Rev[2960]
- CHANGED: module voicemail, deleted unnecessary asterisks
  SVN Rev[2959]
- FIXED: module recordings, variable $pDB and $filename were not defined
  SVN Rev[2957]
- CHANGED: module recordings, database address_book.db is not 
  used. Deleted any relation with that database
  SVN Rev[2954]

* Fri Sep 09 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-5
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-3
- CHANGED: module recordings, changed the location of module
  recordings, now it is in PBX->tools
  SVN Rev[2953]
- CHANGED: module conference, in view mode the asterisks and
  word required were removed
  SVN Rev[2951]
- FIXED: module endpoint_configurator, the version must have 4 decimals
  SVN Rev[2939]
- FIXED: module control_panel, word class is reserved in javascript
  for firefox >= 5. Changed the variable name to other one
  SVN Rev[2934]
- CHANGED: installer.php, deleted the register string of trunks
  created in voipprovider
  SVN Rev[2930]
- ADDED: added script sql for database control_panel_design.db,
  updated the description for the trunk area
  SVN Rev[2928]

* Tue Aug 30 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-4
- CHANGED: In spec file, verify the inclusion of
  sip_notify_custom_elastix.conf on /etc/asterisk/sip_notify_custom.conf
- CHANGED: installer.php, trunks created by provider_account were
  written in file /etc/asterisk/sip_custom.conf. Now when this
  script is executed these trunks are deleted from the mentioned
  file because now the voipprovider trunks are created in freePBX database
  SVN Rev[2925]

* Fri Aug 26 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-3
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-2
- ADDED: In spec file, added prereq elastix-system >= 2.2.0-5
- CHANGED: PBX: Return a diagnostic message instead of exiting 
  when some FreePBX issue disables all modules.
  SVN Rev[2910]
- CHANGED: installer.php, the trunks created in voipprovider
  are also created in the database asterisk of freePBX
  SVN Rev[2890]
- FIXED: module monitoring, fixed many security holes in this module
  SVN Rev[2885]
- CHANGED: module voicemail, if user is not administrator and does
  not have an extension assigned only a message is showed
  SVN Rev[2883]

* Fri Aug 03 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-2
- FIXED: module control_panel, the queues was not showing the
  extension or agent which attends it. Now it shows all the
  extensions or agents that attent it
  SVN Rev[2873]

* Tue Aug 02 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-1
- ADDED: In Spec file added requires festival >= 1.95
- FIXED: module festival, informative message was not displayed. 
  The error was fixed and now it is displayed
  SVN Rev[2863]

# el script de patton query debe moverse a /usr/share/elastix/priviliges en el spec
* Fri Jul 29 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-28
- CHANGED: in spec file changed prereq elastix >= 2.0.4-30
- ADDED: pbx setup/db, sql script to add iax support. SVN Rev[2842]
- ADDED: pbx setub, added the script that searchs for patton 
  devices. SVN Rev[2841]
- ADDED: module endpoint_configurator, added support iax 
  (on phones that support it), also added support to smartnodes.
  SVN Rev[2840]
- FIXED: extensions_override_elastix.conf, when the audio file 
  is not created the field userfield is set empty in the database
  SVN Rev[2821]
- FIXED: module monitoring, when user is not admin the filter 
  options dissapear. Now those options remains with any user.
  SVN Rev[2820]
- CHANGED: module festival, the button save was eliminated, now 
  when user press on or off automatically make the action. SVN Rev[2798]
- CHANGED: module voicemail, changed message when user does not 
  have an extension associated. SVN Rev[2794]
- CHANGED: module monitoring, changed message when a user does 
  not have an extension associated. SVN Rev[2793]
- CHANGED: module voicemail, when the user does not have an 
  extension associated, a link appear to assign one extension.
  SVN Rev[2790]
- CHANGED: module monitoring, The link here 
  (when a user does not have an extension) now open a new window to 
  edit the extension of the user logged in. SVN Rev[2788]
- ADDED: module extensions_batch, added iax2 support. SVN Rev[2774]

* Wed Jun 29 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-27
- FIXED: module festival, added a sleep of 2 seconds when the service
  is started that is the maximum time delay. SVN Rev[2764]

* Mon Jun 13 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-26
- CHANGED: In spec file change prereq freepbx >= 2.8.1-1 and 
  elastix >= 2.0.4-24
- CHANGED: Modules - Trunk: The ereg function was replaced by the 
  preg_match function due to that the ereg function was deprecated 
  since PHP 5.3.0. SVN Rev[2688]
- FIXED: module festival, wrong informative message the file 
  modified is /usr/share/festival/festival.scm and not 
  /usr/share/elastix/elastix.scm. SVN Rev[2669]
- CHANGED: The split function of these modules was replaced by the 
  explode function due to that the split function was deprecated 
  since PHP 5.3.0. SVN Rev[2650]

* Wed May 18 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-25
- CHANGED: change prereq of freePBX to 2.8.0-3

* Wed May 18 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-24
- CHANGED: module pbxadmin, library contentFreePBX.php updated with 
  the last code in pbxadmin
  SVN Rev[2646]
- CHANGED: module pbxadmin, created a library that gets the content
  of freePBX modules
  SVN Rev[2645]
- FIXED: module voipprovider, when a trunk is created by voipprovider
  and then this one is deleted in freePBX, it is not deleted in the
  database of voipprovider. Now its deleted from the database of voipprovider
  SVN Rev[2640]
- ADDED: Conference: new Chinese translations for Conference interface.
  Part of fix for Elastix bug #876
  SVN Rev[2639]

* Thu May 12 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-23
- CHANGED: renamed sql scripts 4 and 5 for updates in database endpoint
  SVN Rev[2638]
- FIXED: Endpoint Configurator: check that selected phone model is
  a supported model before using include_once on it.
  FIXED: Endpoint Configurator: check that MAC address for endpoint
  is valid.
  SVN Rev[2637]
- ADDED: module endpoint_configurator, disabled other accounts in
  YEALINK phones.
  SVN Rev[2635]
- FIXED: File Editor: undo use of <button> inside of <a> as this
  combination does not work as intended in Firefox 4.0.1. Related
  to Elastix bug #864
  SVN Rev[2632]
- FIXED: module pbxadmin, added a width of 330px to the informative
  message in "Unembedded freePBX"
  SVN Rev[2627]
- FIXED: module pbxadmin, the option "Unembedded freePBX" was placed
  at the end of the list, also a warning message was placed on it.
  SVN Rev[2626]

* Thu May 05 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-22
- FIXED:    module pbxadmin, IVR did not displayed extensions, 
  conferences, trunks, etc. Now that information is displayed 
  according to the option selected in the combo box. SVN Rev[2620]
- CHANGED:  PBX - monitoring: Changed  value of 
  $arrConfModule['module_name'] = 'monitoring2' to 
  $arrConfModule['module_name'] = 'monitoring' in default.conf.php
  SVN Rev[2591]

* Tue Apr 26 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-21
- CHANGED: installer.php, changed installer.php in order to works for
  updates to elastix 2.0.4
  SVN Rev[2586]
- FIXED: module control_panel, added a validation in case there is no data
  SVN Rev[2585]
- ADDED: module festival, added folders lang, configs and help
  SVN Rev[2583]
- CHANGED: module voicemail, changed class name to core_Voicemail
  SVN Rev[2580]
- ADDED: added new provider called "Vozelia"
  SVN Rev[2574]
- CHANGED: provider vozelia was removed from the installation script
  SVN Rev[2573]
- CHANGED: module voicemail, changed name from puntosF_Voicemail.class.php
  to core.class.php
  SVN Rev[2571]
- UPDATED: module file editor, some changes with the styles of buttons
  SVN Rev[2561]
- NEW: new scenarios for SOAP in voicemail
  SVN Rev[2559]
- NEW: new module festival
  SVN Rev[2553]
- ADDED: added new module in tools called Festival
  SVN Rev[2552]
- NEW: service festival in /etc/init.d and asterisk file sip_notify_custom_elastix.conf
  SVN Rev[2551]
- CHANGED: In Spec file, moved the files festival and sip_notify_custom_elastix.conf

* Wed Apr 13 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-20
- FIXED: pbx - extension_batch: Removed download_csv.php, this file 
  was removed in commit 1550 but this file was put in this package 
  by error in the rpm version 2.0.4-19.
- ADDED: module endpoint_configurator, added the vendor LG-ERICSSON 
  and the model IP8802A. SVN Rev[2536][2537]
- CHANGED: module endpoint_configurator, changed model names for 
  phones Yealink. SVN Rev[2527][2529][2530]
- ADDED: module endpoint_configurator, added support for 
  phones Yealink models T20, T22, T26 and T28. SVN Rev[2518][2519]

* Tue Apr 04 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-19
- FIXED: module voipprovider, undefined data was set to the 
  combo box. Added a validation for default values in case of 
  an undefined data. SVN Rev[2507]

* Mon Apr 04 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-18
- FIXED: module control_panel, when the area is empty, a box 
  can not be dropped. Now it can. SVN Rev[2498]

* Thu Mar 31 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-17
- FIXED: Error to install databases of sqlite in "process of 
  installation" because in spec file when mysql is down this 
  event is sending to "first-boot" but only mysql scripts and 
  not sqlite.

* Thu Mar 31 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-16
- FIXED: Module Conference, database meetme, bad defintion sql 
  script was fixed. SVN Rev[2477]

* Tue Mar 29 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-15
- ADDED: module voicemail, added a new validation in case the 
  path file does not exist when writing the file voicemail.conf.
  SVN Rev[2469]

* Thu Mar 03 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-14
- CHANGED: module voipprovider, now the provider net2phone is 
  the first in the list of providers. SVN Rev[2391]
- ADDED:  file .sql to create a new column called orden in the 
  table provider of the database trunk.db also the orden field 
  was set for each provider. SVN Rev[2390]

* Tue Mar 01 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-13
- CHANGED: in Spec file change prereq elastix>=2.0.4-10
- ADDED: module control_panel, added a loading image until all 
  the boxes are loaded, also the info window was reduced. 
  SVN Rev[2385]
- CHANGED: module voipprovider, voipprovider now insert the 
  data in the database of freepbx and automatically reload 
  asterisk files. SVN Rev[2384]
- ADDED: database trunk, added a column called id_trunk in 
  table provider_account. SVN Rev[2382]
- FIXED: module voipprovider, the edit mode did not show the 
  data of the account. Now the data is showed. SVN REV[2380]
- FIXED: module voipprovider, fixed the problem of moving down 
  the peer settings options when the width of the browser is 
  smaller. SVN Rev[2378]
- ADDED: module file_editor, added a new button called 
  "Reload Asterisk" that applies the command module reload to 
  asterisk. SVN Rev[2376]
- CHANGED: module endpoint_configurator, added a message when 
  the files are configurated. SVN Rev[2373]
- CHANGED: module enpoint_configurator, changed the field status 
  to current extension which shows the extension to which is 
  registered the phone. SVN Rev[2371]
- FIXED:   Error to try to renove database meetme, change action 
  "drop table meetme" to "drop database meetme". SVN Rev[2365]
- CHANGED: module voipprovider, added a checkbox called advanced 
  that when is checked displays the PEER Setting options. 
  SVN Rev[2358]
- ADDED: module endpoint_configurator, added the configuration 
  for the vendor AudioCodes with models 310HD and 320HD. 
  SVN Rev[2356]
- FIXED: module control_panel, the extensions on the area 1,2 
  and 3 didnt show the status also when you call to a conference 
  or a number that is not an extension the call destiny didn't 
  display. All those problems were fixed. SVN Rev[2355]
- FIXED:  PBX - control Panel: Error in script.sql to update 
  control panel to the next version, The error was the script 
  try to update a table rate but it did not exit and the correct 
  table was area. SVN Rev[2344]

* Mon Feb 07 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-12
- CHANGED:  In Spec file add prerequiste elastix 2.0.4-9

* Mon Feb 07 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-11
- CHANGED:   In Spec add lines to support install or update
  proccess by script.sql.
- DELETED:   Databases sqlite were removed to use the new format 
  to sql script for administer process install, update and delete. 
  In Installer.php remove all instances of .db but the logic to 
  update the old versions of trunk.db is there. SVN Rev[2333]
- ADD:  PBX - setup: New schema organization to get better 
  performance to databases sqlite and mysql. SVN Rev[2328]
- CHANGED: Module conference, meetme database was merged, now
  sql script is 1_schema.sql. SVN Rev[2317]

* Thu Feb 03 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-10
- CHANGED:  menu.xml to support new tag "permissions" where has 
  all permissions of group per module and new attribute "desc" 
  into tag  "group" for add a description of group. 
  SVN Rev[2294][2299]
- CHANGED: module endpoint_configurator, eliminated a print_r.
  SVN Rev[2290]
- ADDED:    database endpoint, added model GXV3175 in the table 
  model. SVN Rev[2287]
- ADDED:    module endpoint_configurator, added model GXV3175. 
  SVN REV[2286]
- ADDED:    database control_panel_design.db, added a new area, 
  parking lots, and added a new column for the color of each 
  area. SVN Rev[2257]
- CHANGED:  module control_panel, new area for parking lots the 
  boxes are generated in the client side and the time counting 
  for the calls are made also in the client side. SVN Rev[2256]
- ADD:      database control_panel_design, added new data in 
  the tabla area for the conferences and the SIP/IAX Trunks. 
  SVN Rev[2237]

* Thu Jan 13 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-9
- CHANGED: In spect file was added script to add permissions
  to "Operador" Group on "Control Panel" Module

* Wed Jan 05 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-8
- UPDATED: Module VoIP Provider, Update codecs of Vozelia
  provider. SVN Rev[2220]
- ADDED: database endpoint, added the model AT 620R in the 
  table model. SVN Rev[2219]
- ADDED: module endpoint_configuration, added a new model of 
  phone for the vendor ATCOM. SVN Rev[2218]

* Wed Jan 05 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-7
- NEW: Module VoIP Provider, New provider Vozelia. SVN Rev[2215]
- FIXED: PBX: Hangup macro now tests if MixMon filename actually
  exists, and clears CDR(userfield) if file is missing (as is 
  the case for NOANSWER call status). Fixes Elastix bug #422. 
  SVN Rev[2209]
- CHANGED: PBX: add comments to extension macros for readability
  SVN Rev[2209]
- FIXED: Monitoring: Do NOT delete CDR from database when 
  deleting audio file. Instead, update CDR to have audio:deleted 
  as its audio file. Also update index.php to cope with this 
  change. SVN Rev[2206]
- CHANGED: Monitoring: do not complain if recording does not 
  exist when deleting it. SVN Rev[2205]
- FIXED: Monitoring: do not reset filter with bogus values at 
  recording removal time. This allows user to realize that 
  recording has indeed been removed when displaying date ranges 
  other than current day. SVN Rev[2204]

* Mon Jan 03 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-6
- UPDATED: Module VoIP Provider, Provider Net2phone codecs 
  updated attributes. SVN Rev[2201]

* Thu Dec 30 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-5
- FIXED: Module Monitoring, Fix bug with record of audio files
  in a conference. SVN Rev[2200]
- CHANGED: module endpoint_configuration, new parameter for the
  phone GXV3140. SVN Rev[2197]

* Thu Dec 30 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-4
- CHANGED: module endpoint_configurator, new parameters for the 
  configuration of the phones grandstream and renamed the names 
  of the files with the configuration. SVN Rev[2187]
- CHANGED: database endpoint, four new models were inserted. 
  SVN Rev[2186]
- CHANGED: Module VoIP Provider, change ip 208.74.169.86, for 
  gateway.circuitid.com of provider CircuitID. SVN Rev[2180]

* Tue Dec 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-3
-  NEW: New file macros elastix, old files macro hangup and
   macro record was remove as sources of RPM and put in tar file
   of PBX. SVN Rev[2167]

* Mon Dec 27 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-2
- CHANGED: In Spec file add new prereq elastix-my_extension to 
  remove the old instance of myextension of elastix-pbx
- FIXED: In Database Voip Provider appear a warning after to 
  install, this warning appear in the moment to read the old 
  database to replace during a update. SVN Rev[2159]

* Mon Dec 20 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-1
- CHANGED: Add tftpboot, openfire, ftp and vsftp in spec file as 
  part of process install and post. This configurations were in
  elastix.spec
- NEW:     Module VoIP Provider, new provider CircuitID was added.
  SVN Rev[2120]
- DELETED:  Module myextension of PBX wax remove and moved to 
  new main menu called My Extension. SVN Rev[2113]
- NEW:     New files of vsftpd, xinetd.d folders and 
  vsftpd.user_list file in setup/etc in modules/trunk/pbx/, now 
  the spec of elastix.pbx use and required these services
  SVN Rev[2109]
- NEW:     Tftpboot in setup of pbx was added from trunk, it is 
  for get a better organization. SVN Rev[2106]
- CHANGED: Module endpoint configurator, DTMF in phones atcom, 
  are configurated to send on rfc2833. SVN Rev[2093]

* Mon Dec 06 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-42
- CHANGED: Add new Prereq freePBX in spec file
- FIXED:  Quit menu=module_name as parameters to send for ajax to 
  identify the menu to get data. This change was done in javascript
  in voipProvider module. SVN Rev[2042]
- CHANGED:    Module monitoring, to export data from reports the
  output contain text html if the report to export has any styles
  or html elements as part of grid. The solution was changing the
  data to export only if the request is export so, the data(array) 
  can be returned without html elements only the data from 
  database, it is in paloSantoGrid.class.php in commit 2024.
  SVN Rev[2034]
- CHANGED:    Module VOIP Provider was changed and new functionality 
  were done, for example the creation of new account and custom 
  accounts. SVN Rev[2025]
- FIXED: Module monitoring, variable $file no found in commit 2011.
  SVN Rev[2016]
- CHANGED: massive search and replace of HTML encodings with the
  actual characters. SVN Rev[2002]
- FIXED:   Conference: detect Asterisk version on the fly to 
  decide whether to use a pipe or a comma to separate arguments 
  for an Asterisk application. Fixes Elastix bug #578. SVN Rev[1998]
- FIXED:   Conference: properly escape HTML characteres to prevent 
  XSS in grid display of conferences. SVN Rev[1992]
- CHANGED: stop assigning template variable "url" directly, and 
  remove nested <form> tag. The modules with those changes are:
  Conference SVN Rev[1992], Voicemail SVN Rev[1990], 
  Endpoint Configurator SVN Rev[1984]
- FIXED: Voicemail: emit proper 404 HTTP header when denying 
  access to a recording. SVN Rev[1990]
- CHANGED: Voicemail: synchronize index.php between Elastix 1.6
  and Elastix 2. SVN Rev[1987]
- FIXED: File Editor: complete rewrite. This rewrite achieves 
  the following:
         Add proper license header to module file
         Improve readability of code by splitting file listing 
           and file editing into separate procedures
         Remove opportunities for XSS in file list navigation 
           (ongoing fix for Elastix bug #572)
         Remove opportunities for XSS in file content viewing.
         Remove possible opportunity for arbitrary command 
           execution due to nonvalidated exec()
         Fix unintended introduction of DOS line separators when 
           saving files.
         Remove nested <form> tags as grid library already 
           introduces them.
  SVN Rev[1983]

* Fri Nov 26 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-41
- FIXED:  Monitoring module, the problem was that the recordings 
  of the queues "the audio file" if it was created but not saved 
  the information in the database. For the solution 
  extensions_override_freepbx.conf file was modified to add the 
  information stored in database at the time of the hangup, and 
  the respective changes in Monitoring Module. SVN Rev[2011]

* Mon Nov 15 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-40
- FIXED:  Fixed bug where use $oForm->fetchForm in the function
  load_extension in extension batch and never was used. SVN Rev[1953]

* Fri Nov 12 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-39
- FIXED:change style in the tittle of the module my extension.
  SVN Rev[1946]
- FIXED: make module aware of url-as-array in paloSantoGrid.
     Split up URL construction into an array.
     Assign the URL array as a member of the $arrGrid structure.
     Remove <form> tags from the filter HTML template fetch. They are 
      not required, since the template already includes a proper <form> 
      tag enclosing the grid.
     Part of fix for Elastix bug #572. Requires commits 1901 and 1902 
      in order to work properly.
  SVN Rev[1915]
- FIXED: Problem with changing the page, when searching and want to move 
  from page to page the search pattern is lost, also did not show the 
  correct amount of the results, related bug [# 564] of bugs.elastix.org. 
  Also had the problem that in the link of the page showing all the names 
  of the files as parameters of GET request. The solution was to change 
  the way to build the url. Also the way to change the filter to obtain 
  data for both GET and POST. SVN Rev[1904]

* Fri Nov 05 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-38
- ADDED: Create folder version_sql in update process. SVN Rev[1896]
- FIXED: day night modes cannot be edited in Elastix embedded
  freePBX, [#576] www.bugs.elastix.org. SVN Rev[1893]
- CHANGED: Routine maintenance, changed the name of the file and
  remove lines that do nothing to create folders that were not used.
  SVN Rev[1891]

* Sat Oct 30 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-37
- FIXED:  Add macros in /etc/asterisk/extensions_override_freepbx.conf
  but asterisk never is reloaded. Changes in SPEC

* Fri Oct 29 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-36
- FIXED:  File migartionFileMontor.php was not work fine. 
  Some monitoring audio files were not written SVN Rev[1877]
- FIXED:  Fixed bug where users cannot listen the audios in 
  monitoring. [#563].SVN Rev[1875]

* Thu Oct 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-35
- FIXED:  Change move migrationFilesMonitor.php into folder installer
  /usr/share/elastix/"moduleInstall"/setup/

* Thu Oct 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-34
- CHANGED: Add headers of information in migrationFilesMonitor.php.
  SVN Rev[1868]

* Wed Oct 27 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-33
- CHANGED: Spec file was change. New file migrationFilesMonitor.php, it 
  was removed from elastix.spec and now it part of the source of 
  elastix-pbx. SVN Rev[1866]

* Wed Oct 27 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-32
- CHANGED: Updated the Bulgarian language elastix. SVN Rev[1857]
- FIXED:  Batch Of Extensions Problems with Outbound CID and Inbound DID, 
  they don't appear this fields in csv files to download. 
  More details in http://bugs.elastix.org/view.php?id=447. SVN Rev[1853]

* Tue Oct 26 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-31
- CHANGED: The Spec file valid if version and release are lower to 
  1.6.2-13 for doing migration of monitoring audio files. It is only for
  migration Elastix 1.6 to 2.0

* Tue Oct 26 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-30
- CHANGED: Move line elastix-menumerge at beginning the "%post" in spec file. 
  It is for the process to update.

* Mon Oct 18 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-29
- FIXED:   Fixed security bug with audio.php and popup.php where an user can be download 
  files system without authentication. See in http://bugs.elastix.org/view.php?id=552
  SVN Rev[1833]
- CHNAGED: Language fr.lang was updated. SVN Rev[1825]
- ADDED:   New lang file fa.lang. SVN Rev[1823]
- FIXED:   It validates that the index of the callerid exist, if it don't 
  exits the callerid is left. This fixes a problem that did not display the number of
  participants at the conference when it is an outside call. 
  Bug [#491]. Bug [#491] SVN Rev[1814]

* Mon Sep 27 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-28
- FIXED:    include character '/' in function isDialpatternChar where character / (match cid) not valid for dial pattern in outbound routes. SVN Rev[1754], Bug[#485] 

* Tue Sep 14 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-27
- CHANGED: rework translation support so that it will work with untranslated English strings for new menu items. Rev[1734]
- FIXED:   add several new menu items for FreePBX menus, to make them appear on the embedded interface. Should fix Elastix bug #458. Rev[1734]
- FIXED:   Valid fields with only spaces blank. Rev[1740]
- FIXED:   actually implement paging correctly on discovered endpoint list. Should fix Elastix bug #411. Rev[1732]
- FIXED:   preserve list of discovered endpoints across page refreshes until next reload. Rev[1732]
- CHANGED: enforce sorting by IP on list of discovered endpoints. Rev[1732]

* Mon Aug 23 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-26
- REMOVE: Remove extensions_override_freepbx.conf in Sources for many macros as macro-record-enable and macro-hangupcall. 

* Mon Aug 23 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-25
- FIXED: Fixed bug[#409] and change the Source extensions_override_freepbx.conf.

* Fri Aug 20 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-24
- FIXED: Fix incorrect wording for text string. Part of fix for bug #421. Rev[1724]
- FIXED: Merge translations strings from local language with English. This allows module to display English text if localized text is unavailable, instead of showing blanks. Rev[1721]
- FIXED: do not use uninitialized array indexes when logged-on user has no extension. Rev[1718]

* Thu Aug 12 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-23
- NEW:     New module My Extension in PBX, It configure the user's extension from elastix web interface. Rev[1694]

* Sat Aug 07 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-22
- CHANGED: Change help files in  Operator Panel, Endpoint Configurator, VoIP Provider.
           Change the module name to Operator Panel
- CHANGED: Task [#243] extension batch. Now if no extension availables the file downloaded show all columns about the information that it must have...

* Wed Jul 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-21
- FIXED: Script is not authenticated session, and anyone from the internet can be invoked with arbitrary parameters.
-        Expose connection data of known IP providers

* Fri Jul 23 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-20
- NEW: Implementation to support install database in fresh install.

* Fri Jul 23 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-19
- CHANGED: database module conference (meetme db) was removed in process index.php instalation in web interface. Now the install db is with to help elastix-dbprocess.

* Fri Jul 23 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-18
- DELETED: Source module realtime, this module is devel yet.
- CHANGED: String connection database to asteriskuser in module monitoring.

* Fri Jul 23 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-17
- CHANGED: Name module to Operator Panel.
- CHANGED: String connection database to asteriskuser. 

* Thu Jul 01 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-16
- FIXED:    Add line 'global $recordings_save_path' in pbxadmin module to obtain the path where upload audio files in recording [#346] bugs.elastix.org

* Thu Jun 17 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-15
- Change module extensions batch. Now the option download cvs is processing by index.php 

* Thu Apr 15 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-14
- Change port 5061 to 5060 in file config vendor Cisco.cfg.php module endpoint configurator.
- Fixed bug module extension batch, wasn't validating the file csv. Error code in compration boolean expresion.
- Fixed bug in module monitoring not had been changed  the new code.
- Be improve the look in module voip provider.


* Thu Mar 25 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-13
- Re-write macro-record-enable for freePBX, this action is for module monitorin support new programming in based database asteriskcdrdb.
- Module Monitoring was rewrited, improved behavoir in search audio files. 

* Fri Mar 19 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-12
- Defined Lang missed. 

* Tue Mar 16 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-11
- Defined number order menu.

* Mon Mar 01 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-10
- Fixed minor bug in EOP.

* Wed Dec 30 2009 Bruno Macias <bmacias@palosanto.com> 2.0.0-6
- Fixed bug module Voip Provider, change name voip-provider-cust to voip-provider.

* Tue Dec 29 2009 Bruno Macias <bmacias@palosanto.com> 2.0.0-7
- Improved module control panel support multi columns.
- Fixed bug, boxes of extension into other them.
- Improved module VOIP Provider performance.

* Fri Dec 04 2009 Bruno Macias <bmacias@palosanto.com> 2.0.0-6
- Improved the modulo voip provider, validation and look.

* Mon Oct 19 2009 Alex Villacis <bmacias@palosanto.com> 2.0.0-5
- Inplemetation for support web conferences in module cenferences elastix.

* Fri Sep 18 2009 Bruno Macias <bmacias@palosanto.com> 2.0.0-4
- New module VOIP PROVIDER
- Fixed minor bugs in definition words languages and messages.
- Add accion uninstall rpm.

* Fri Sep 18 2009 Bruno Macias <bmacias@palosanto.com> 2.0.0-3
- Add words in module coference.

* Mon Sep 07 2009 Bruno Macias <bmacias@palosanto.com> 2.0.0-2
- New structure menu.xml, add attributes link and order.

* Wed Aug 26 2009 Bruno Macias <bmacias@palosanto.com> 1.0.0-1
- Initial version.
