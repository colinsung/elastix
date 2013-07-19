Summary: LCDproc client for Elastix status display
Name: lcdelastix
Version: 1.3.0
Release: 1
License: GPL
Group: Applications/System
Source0: lcdelastix-%{version}.tar.bz2
BuildRoot: %{_tmppath}/%{name}-%{version}-%{release}-buildroot
BuildArch: noarch
Requires: php >= 5.0.0
Requires: elastix-framework
Requires: lcdproc >= 1:0.5.5

%description

This is a daemon and a set of utilities that implement the Elastix status 
output in the LCD display of Elastix appliances. This daemon should not be
running on a device without a LCD display, unless you know what you are doing.

%prep
%setup -n lcdelastix

%install
rm -rf %{buildroot}
mkdir -p  %{buildroot}/opt/lcdelastix/
rm -f *.spec
cp -r * %{buildroot}/opt/lcdelastix/
mkdir -p %{buildroot}%{_sysconfdir}/rc.d/init.d
install -m 0755 %{buildroot}/opt/lcdelastix/lcdelastix  %{buildroot}/etc/rc.d/init.d/lcdelastix

%clean
rm -rf %{buildroot}

%pre

%post
# Add LCDd service, but do not enable it. Prevents program from unnecessarily 
# running on a system without a LCD display
/sbin/chkconfig --add lcdelastix
/sbin/chkconfig lcdelastix off

%preun
if [ $1 -eq 0 ] ; then # Check to tell apart update and uninstall
	/sbin/chkconfig --del lcdelastix
fi

%files
%defattr(-,root,root,-)
%{_sysconfdir}/rc.d/init.d/lcdelastix
%defattr(-,asterisk,asterisk,-)
/opt/lcdelastix/*

%changelog
* Wed Mar 14 2012 Alex Villacis Lasso <a_villacis@palosanto.com> 1.3.0-1
- FIXED: additionals - lcdelastix/lcdapplets/ch.php: Se muestra mensaje de error
  en el shell cuando se accede a PBX Activity>Concurr Channels con el LCD del 
  appliance. Bug 0001098. SVN Rev[3528]

* Tue Mar 13 2012 Alex Villacis Lasso <a_villacis@palosanto.com> 1.3.0-0
- Forked lcdelastix to separate package lcdelastix
