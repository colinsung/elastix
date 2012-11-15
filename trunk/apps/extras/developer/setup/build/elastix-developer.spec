%define modname developer

Summary: Elastix Module Developer
Name:    elastix-%{modname}
Version: 2.3.0
Release: 3
License: GPL
Group:   Applications/System
Source0: %{modname}_%{version}-%{release}.tgz
#Source0: %{modname}_%{version}-1.tgz
BuildRoot: %{_tmppath}/%{name}-%{version}-root
BuildArch: noarch
Prereq: elastix-framework >= 2.3.0-6

%description
Elastix Module Developer

%prep
%setup -n %{modname}

%install
rm -rf $RPM_BUILD_ROOT

# Files provided by all Elastix modules
mkdir -p    $RPM_BUILD_ROOT/var/www/html/
mv modules/ $RPM_BUILD_ROOT/var/www/html/

# Additional (module-specific) files that can be handled by RPM
#mkdir -p $RPM_BUILD_ROOT/opt/elastix/
#mv setup/dialer

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
rm -rf /tmp/new_module

%clean
rm -rf $RPM_BUILD_ROOT

%preun
if [ $1 -eq 0 ] ; then # Validation for desinstall this rpm
  echo "Delete developer menus"
  elastix-menuremove "%{modname}"
fi

%files
%defattr(-, asterisk, asterisk)
%{_localstatedir}/www/html/*
/usr/share/elastix/module_installer/*

%changelog
* Wed Jul 11 2012 Alberto Santos <asantos@palosanto.com> 2.3.0-3
- CHANGED: In spec file, changed prereq elastix-framework >= 2.3.0-6
- FIXED: module language_admin, words with a key that has spaces were
  not able to change the value. To fix this problem, a new hidden input
  was added to the form which contains the key of the word
  SVN Rev[4061]
- FIXED: module language_admin, fixed mantis bug #1317, number of 
  pages was not displayed and also keys with the character '_' were
  not able to change
  SVN Rev[4053]

* Fri Apr 27 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-2
- CHANGED: extras module build_module, changed the use from xajax to the
  framework function "requestrequest"
  SVN Rev[3877]
- ADDED: Setup - build: Added a folder for svn restructuration.
  SVN Rev[3860]

* Wed Mar 07 2012 Rocio Mera <rmera@palosanto.com> 2.3.0-1
- CHANGED: In spec file changed prereq elastix-framework >= 2.3.0-1
- CHANGED: language_admin index.php add control to applied filters
  SVN Rev[3717]
- UPDATED:
  SVN Rev[3549]
- CHANGED: Modules - Extras: Added support for the new grid layout.
  SVN Rev[3547]

* Tue Jan 17 2012 Alberto Santos <asantos@palosanto.com> 2.2.0-3
- CHANGED: In spec file changed prereq elastix-framework >= 2.2.0-25
- FIXED: modules extras delete_modules, when trying to delete
  second or third modules level, the combos "Level 2" and
  "Level 3" are empty. This bug was introduced due to the
  improves in index.php for filtering menu.
  SVN Rev[3534]
- FIXED: modules extras build_module, when trying to create third
  modules level, the combo "Level 2 Parent" was empty. This bug was
  introduced due to the improves in index.php for filtering menu.
  FIXED: modules extras build_module, this module was adapted to
  the new standard in Elastix 2.2.0 in which the title and icon
  of the module are handled by the framework
  SVN Rev[3533]

* Fri Nov 25 2011 Eduardo Cueva <ecueva@palosanto.com> 2.2.0-2
- CHANGED: In spec file changed Prereq elastix to
  elastix-framework >= 2.2.0-18-
- CHANGED: module load_module, now the module title is handled by
  the framework. SVN Rev[3288]
- CHANGED: module language_admin, now the module title is handled
  by the framework. SVN Rev[3287]
- CHANGED: module delete_module, now the module title is handled
  by the framework. SVN Rev[3286]
- CHANGED: module build_module, now the module title is handled
  by the framework. SVN Rev[3285]

* Wed Sep 28 2011 Alberto Santos <asantos@palosanto.com> 2.2.0-1
- FIXED: module load_module, the value of "order" is now
  considered for adding a new menu
  SVN Rev[3013]
- CHANGED: The split function of these modules was replaced
  by the explode function due to that the split function was
  deprecated since PHP 5.3.0.
  SVN Rev[2650]

* Tue Apr 05 2011 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-2
- CHANGED: module build_module, missed tag >. SVN Rev[2513]

* Tue Dec 28 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.4-1
- CHANGED: Module Developer, change format URL to be a array,
  this in the case of modules type of grid. SVN Rev[2164]
- CHANGED: Module Developer, change array of language $arrLang
  to the function _tr() and a updating the modules type of grid
  to support new methods of paloSantoGrid.class.php. SVN Rev[2163]
- UPDATED: Updated source of modules type of grid to support export
  in format PDFs, EXCEL y CSV. SVN Rev[1895]

* Sat Aug 07 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-4
- FIXED:     Change document root by conf variable $arrConf.

* Mon Jun 07 2010 Eduardo Cueva <ecueva@palosanto.com> 2.0.0-3
- Fixed bug, where the position module install was 0 before the other menus like system,agend, and so on.

* Wed Feb 03 2010 Bruno Macias <bmacias@palosanto.com> 2.0.0-2
- Update module.

* Mon Oct 19 2009 Bruno Macias <bmacias@palosanto.com> 2.0.0-1
- Initial version.
