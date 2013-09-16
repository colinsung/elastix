%define modname my_extension

Summary: Elastix My Extension 
Name:    elastix-%{modname}
Version: 3.0.0
Release: 3
License: GPL
Group:   Applications/System
Source0: %{modname}_%{version}-%{release}.tgz
#Source0: %{modname}_%{version}-4.tgz
BuildRoot: %{_tmppath}/%{name}-%{version}-root
BuildArch: noarch
Prereq: elastix-framework >= 3.0.0-1
Requires: yum

%description
Elastix My Extension

%prep
%setup -n %{modname}

%install
rm -rf $RPM_BUILD_ROOT

# Files provided by all Elastix modules
#mkdir -p    $RPM_BUILD_ROOT/var/www/html/
mkdir -p $RPM_BUILD_ROOT/usr/share/elastix/apps/%{name}/
bdir=%{_builddir}/%{modname}
if [ -d $bdir/modules/$FOLDER0/$FOLDER1/web/ ]; then
for FOLDER0 in $(ls -A modules/)
do
		for FOLDER1 in $(ls -A $bdir/modules/$FOLDER0/)
		do
				mkdir -p $RPM_BUILD_ROOT/usr/share/elastix/apps/%{name}/$FOLDER1/
				for FOLFI in $(ls -I "web" $bdir/modules/$FOLDER0/$FOLDER1/)
				do
					if [ -d $bdir/modules/$FOLDER0/$FOLDER1/$FOLFI ]; then
						mkdir -p $RPM_BUILD_ROOT/usr/share/elastix/apps/%{name}/$FOLDER1/$FOLFI
						if [ "$(ls -A $bdir/modules/$FOLDER0/$FOLDER1/$FOLFI)" != "" ]; then
						mv $bdir/modules/$FOLDER0/$FOLDER1/$FOLFI/* $RPM_BUILD_ROOT/usr/share/elastix/apps/%{name}/$FOLDER1/$FOLFI/
						fi
					elif [ -f $bdir/modules/$FOLDER0/$FOLDER1/$FOLFI ]; then
						mv $bdir/modules/$FOLDER0/$FOLDER1/$FOLFI $RPM_BUILD_ROOT/usr/share/elastix/apps/%{name}/$FOLDER1/
					fi
				done
				case "$FOLDER0" in 
					frontend)
						mkdir -p $RPM_BUILD_ROOT/var/www/html/web/apps/$FOLDER1/
						if [ -d $bdir/modules/$FOLDER0/$FOLDER1/web/ ]; then
							mv $bdir/modules/$FOLDER0/$FOLDER1/web/* $RPM_BUILD_ROOT/var/www/html/web/apps/$FOLDER1/
						fi
					;;
					backend)
						mkdir -p $RPM_BUILD_ROOT/var/www/html/admin/web/apps/$FOLDER1/
						if [ -d $bdir/modules/$FOLDER0/$FOLDER1/web/ ]; then
							mv $bdir/modules/$FOLDER0/$FOLDER1/web/* $RPM_BUILD_ROOT/var/www/html/admin/web/apps/$FOLDER1/
						fi	
					;;
				esac
		done
done
fi

# The following folder should contain all the data that is required by the installer,
# that cannot be handled by RPM.
mkdir -p    $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
mv setup/   $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/
mv menu.xml $RPM_BUILD_ROOT/usr/share/elastix/module_installer/%{name}-%{version}-%{release}/

%post

# Run installer script to fix up ACLs and add module to Elastix menus.
elastix-menumerge /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/menu.xml

# The installer script expects to be in /tmp/new_module
mkdir -p /tmp/new_module/%{modname}
cp -r /usr/share/elastix/module_installer/%{name}-%{version}-%{release}/* /tmp/new_module/%{modname}/
chown -R asterisk.asterisk /tmp/new_module/%{modname}

php /tmp/new_module/%{modname}/setup/installer.php

#verificando si existe el menu en pbx
path="/var/www/db/acl.db"
path2="/var/www/db/menu.db"
men=`sqlite3 $path2 "select id from menu where id='myextension'"`
if [ $men ]; then
     echo "removing menu myextension"
     elastix-menuremove "myextension"
fi
res=`sqlite3 $path "select id from acl_resource  where name='myex_config'"`
res2=`sqlite3 $path "select id from acl_group"`
#asignando los permisos a los grupos de usuarios para el modulo my_extension
for group in $res2
do
  if [ $group != 1 ]; then
     #exist registers in acl.db
     val=`sqlite3 $path "select * from acl_group_permission where id_group=$group and id_resource=$res"`
     if [ -z $val ]; then
          echo "updating group with id=$group for default My extension module"
          `sqlite3 $path "insert into acl_group_permission(id_action, id_group, id_resource) values(1,$group,$res)"`
     fi
  fi
done

rm -rf /tmp/new_module

%clean
rm -rf $RPM_BUILD_ROOT

%preun
if [ $1 -eq 0 ] ; then # Validation for desinstall this rpm
  echo "Delete My Extension menus"
  elastix-menuremove "%{modname}"
fi

%files
%defattr(-, root, root)
#%{_localstatedir}/www/html/*
/usr/share/elastix/module_installer/*
/usr/share/elastix/apps/*

%changelog
* Fri Sep 13 2013 Luis Abarca <labarca@palosanto.com> 3.0.0-3
- CHANGED: my_extension - Build/elastix-my_extension.spec: update specfile with latest
  SVN history. Bump Release in specfile.

* Wed Sep 11 2013 Luis Abarca <labarca@palosanto.com> 
- ADDED: my_extension - setup/infomodules.xml/: Within this folder are placed
  the new xml files that will be in charge of creating the menus for each
  module.
  SVN Rev[5863]

* Wed Sep 11 2013 Luis Abarca <labarca@palosanto.com> 
- CHANGED: my_extension - modules: The modules were relocated under the new
  scheme that differentiates administrator modules and end user modules .
  SVN Rev[5862]

* Thu Jul 04 2013 Luis Abarca <labarca@palosanto.com> 
- CHANGED: trunk - myex_config/: It was corrected a configuration in the web
  folder.
  SVN Rev[5239]

* Tue Jul 02 2013 Luis Abarca <labarca@palosanto.com> 
- CHANGED: trunk - myex_config/: The svn repository for module myex_config in
  trunk (Elx 3) was restructured in order to accomplish a new schema.
  SVN Rev[5152]

* Mon May 27 2013 Luis Abarca <labarca@palosanto.com> 3.0.0-2
- CHANGED: my_extension - Build/elastix-my_extension.spec: update specfile with latest
  SVN history. Bump Release in specfile.
  SVN Rev[5028]

* Tue Mar 14 2013 Alex Villacis Lasso <a_villacis@palosanto.com>
- FIXED: My Extension: check that the values for record_in and record_out are
  valid before writing to the database. Fixes Elastix bug #1554.
  SVN Rev[4928]

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
- CHANGED: my_extension - Build/elastix-my_extension.spec: Changed version and 
  release in specfile.
  SVN Rev[4228]
- CHANGED: In spec file changed Prereq elastix-framework to
  elastix-framework >= 3.0.0-1
- CHANGED: In spec file changed source0 to %{modname}_%{version}-%{release}.tgz
  SVN Rev[4233]

* Fri Nov 25 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-6
- CHANGED: In spec file changed Prereq elastix to
  elastix-framework >= 2.2.0-18

* Sat Oct 29 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-5
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-13

* Sat Oct 29 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-4
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-12
- UPDATED: my extesion modules  templates files support new 
  elastixneo theme. SVN Rev[3156]

* Fri Oct 07 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-3
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-8
- CHANGED: module my_exconfig, the word "required field" was deleted
  SVN Rev[3028]

* Tue Sep 27 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-2
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-5
- CHANGED: changed the password "elastix456" of AMI to the
  password set in /etc/elastix.conf
  SVN Rev[2995]

* Mon Aug 29 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-1
- CHANGED: In spec file, changed prereq elastix >= 2.2.0-2
- CHANGED: module myex_config, if user does not have an extension
  assigned, only a message is showed instead of the module
  SVN Rev[2882]

* Tue Jul 28 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-7
- CHANGED: module myex_config, changed message when user does not 
  have an extension associated. SVN Rev[2795]
- CHANGED: module myex_config, The link here (when a user does not 
  have an extension) now open a new window to edit the extension 
  of the user logged in. SVN Rev[2789]

* Tue Apr 26 2011 Alberto Santos <asantos@palosanto.com> 2.0.4-6
- FIXED: module myex_config, undefined variables  
  $request->recordIncoming and $request->recordOutgoing
  SVN Rev[2587]
- CHANGED: module my_extension, changed class name to 
  core_MyExtension. SVN Rev[2579]
- CHANGED: module my_extension, changed name from 
  puntosF_MyExtension.class.php to core.class.php. SVN Rev[2572]
- NEW: new scenarios for SOAP in myex_config
- CHANGED: In Spec file add prerequisite elastix 2.0.4-19

* Mon Feb 07 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-5
- CHANGED:  In Spec file add prerequiste elastix 2.0.4-9

* Thu Feb 03 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-4
- CHANGED:  menu.xml to support new tag "permissions" where has 
  all permissions of group per module and new attribute "desc" 
  into tag  "group" for add a description of group. 
  SVN Rev[2294][2299]

* Wed Jan 05 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-3
- FIXED: My Extension: In addition to technology table, the 
  'users' table must be updated as well when modifying recording 
  settings. SVN Rev[2222]

* Tue Dec 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-2
- CHANGED:  Clean code in installer.php of module my_extension

* Thu Dec 16 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-1
- Initial version.

