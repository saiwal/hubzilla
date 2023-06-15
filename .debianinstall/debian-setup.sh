#!/bin/bash
#
# How to use
# ----------
#
# This file automates the installation of hubzilla: https://framagit.org/hubzilla/core
# under Debian Linux "bookworm"
#
# 1) Copy the file "config.txt.template" to "config.txt"
#       Follow the instuctions there
#
# 2) Switch to user "root" by typing "su -"
#
# 3) Run with "./debian-setup.sh"
#       If this fails check if you can execute the script.
#       - To make it executable type "chmod +x debian-setup.sh"
#       - or run "bash debian-setup.sh"
#
#
# What does this script do basically?
# -----------------------------------
#
# This file automates the installation of a Hubzilla instance under Debian Linux
# - install
#        * apache webserver,
#        * php,
#        * mariadb,
#        * adminer,
#        * addons
# - configure cron
#        * "Master.php" for regular background processes of your hubzilla instance
#        * "apt-get update" and "apt-get dist-upgrade" and "apt-get autoremove" to keep linux up-to-date
#        * run command to keep the IP up-to-date > DynDNS provided by selfHOST.de or freedns.afraid.org
# - run letsencrypt to create, register and use a certifacte for https
#
#
# Credits
# -------
#
# The script is derived from the easyinstall script of the Streams repository, which is based on
# - Tom Wiedenhöfts (OJ Random) script homeinstall (for Hubzilla, ZAP,...) that was based on
# - Thomas Willinghams script "debian-setup.sh" which he used to install the red#matrix.

function check_sanity {
    # Do some sanity checking.
    print_info "Sanity check..."
    if [ $(/usr/bin/id -u) != "0" ]
    then
        die 'Must be run by root user'
    fi

    if [ -f /etc/lsb-release ]
    then
        die "Distribution is not supported"
    fi
    if [ ! -f /etc/debian_version ]
    then
        die "Debian is supported only"
    fi
    if ! grep -q 'Linux 12' /etc/issue
    then
        die "Linux 12 (bookworm) is supported only"x
    fi
}

function check_config {
    print_info "config check..."
    # Check for required parameters
    if [ -z "$db_pass" ]
    then
        die "db_pass not set in $configfile"
    fi
    if [ -z "$le_domain" ]
    then
        die "le_domain not set in $configfile"
    fi
}

function die {
    echo "ERROR: $1" > /dev/null 1>&2
    exit 1
}


function update_upgrade {
    print_info "updated and upgrade..."
    # Run through the apt-get update/upgrade first. This should be done before
    # we try to install any package
    apt-get -q -y update && apt-get -q -y dist-upgrade && apt-get -q -y autoremove
    print_info "updated and upgraded linux"
}

function nocheck_install {
    # export DEBIAN_FRONTEND=noninteractive ... answers from the package configuration database
    # - q ... without progress information
    # - y ... answer interactive questions with "yes"
    # DEBIAN_FRONTEND=noninteractive apt-get --no-install-recommends -q -y install $2
    # DEBIAN_FRONTEND=noninteractive apt-get --install-suggests -q -y install $1
    DEBIAN_FRONTEND=noninteractive apt-get -q -y install $1
    print_info "installed $1"
}


function print_info {
    echo -n -e '\e[1;34m'
    echo -n $1
    echo -e '\e[0m'
}

function print_warn {
    echo -n -e '\e[1;31m'
    echo -n $1
    echo -e '\e[0m'
}

function stop_zotserver {
    print_info "stopping apache..."
    systemctl stop apache2
    print_info "stopping mysql db..."
    systemctl stop mariadb
}

function install_apache {
    print_info "installing apache..."
    nocheck_install "apache2 apache2-utils"
    a2enmod rewrite
    systemctl restart apache2
}

function install_imagemagick {
    print_info "installing imagemagick..."
    nocheck_install "imagemagick"
}

function install_curl {
    print_info "installing curl..."
    nocheck_install "curl"
}

function install_wget {
    print_info "installing wget..."
    nocheck_install "wget"
}

function install_sendmail {
    print_info "installing sendmail..."
    nocheck_install "sendmail sendmail-bin"
}

function install_php {
    # openssl and mbstring are included in libapache2-mod-php
    print_info "installing php..."
    nocheck_install "libapache2-mod-php php php-pear php-curl php-gd php-mbstring php-xml php-zip"
    phpversion=$(php -v|grep --only-matching --perl-regexp "(PHP )\d+\.\\d+\.\\d+"|cut -c 5-7)
    sed -i "s/^upload_max_filesize =.*/upload_max_filesize = 100M/g" /etc/php/$phpversion/apache2/php.ini
    sed -i "s/^post_max_size =.*/post_max_size = 100M/g" /etc/php/$phpversion/apache2/php.ini
}

function install_composer {
    print_info "We check if Composer is already downloaded"
    if [ ! -f /usr/local/bin/composer ]
    then
        EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
        php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
        ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
        if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]
        then
            >&2 echo 'ERROR: Invalid installer checksum'
            rm composer-setup.php
            die 'ERROR: Invalid installer checksum'
        fi
        php composer-setup.php --quiet
        RESULT=$?
        rm composer-setup.php
        # exit $RESULT
        # We install Composer globally
        mv composer.phar /usr/local/bin/composer
        print_info "Composer was successfully downloaded."
    else
        print_info "Composer is already downloaded on this system."
    fi
    cd $install_path
    export COMPOSER_ALLOW_SUPERUSER=1;
    /usr/local/bin/composer install --no-dev
    /usr/local/bin/composer show
    export COMPOSER_ALLOW_SUPERUSER=0;
}


function install_mysql {
    print_info "installing mysql..."
    if [ -z "$mysqlpass" ]
    then
        die "mysqlpass not set in $configfile"
    fi
	if type mysql ; then
        echo "Yes, mysql is installed"
	else
        echo "mariadb-server"
        nocheck_install "mariadb-server"
        systemctl status mariadb
        systemctl start mariadb
        mysql --user=root <<_EOF_
UPDATE mysql.user SET Password=PASSWORD('${mysqlpass}') WHERE User='root';
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
_EOF_
    fi
}

function install_adminer {
    print_info "installing adminer..."
    nocheck_install "adminer"
    if [ ! -f /etc/adminer/adminer.conf ]
    then
        echo "Alias /adminer /usr/share/adminer/adminer" > /etc/adminer/adminer.conf
        ln -s /etc/adminer/adminer.conf /etc/apache2/conf-available/adminer.conf
    else
        print_info "file /etc/adminer/adminer.conf exists already"
    fi

    a2enmod rewrite

    if [ ! -f /etc/apache2/apache2.conf ]
    then
        die "could not find file /etc/apache2/apache2.conf"
    fi
    sed -i \
        "s/AllowOverride None/AllowOverride all/" \
        /etc/apache2/apache2.conf

    a2enconf adminer
    systemctl restart mariadb
    systemctl reload apache2
}

function create_zotserver_db {
    print_info "creating zotserver database..."
    if [ -z "$db_name" ]
    then
        die "db_name not set in $configfile"
    fi
    if [ -z "$db_user" ]
    then
        die "db_user not set in $configfile"
    fi
    if [ -z "$db_pass" ]
    then
        die "db_pass not set in $configfile"
    fi
    systemctl restart mariadb
    # Make sure we don't write over an already existing database if we install more than one Zot hub/instance
    if [ -z $(mysql -h localhost -u root -p$mysqlpass -e "SHOW DATABASES;" | grep $db_name) ]
    then
        Q1="CREATE DATABASE IF NOT EXISTS $db_name;"
        Q2="GRANT USAGE ON *.* TO $db_user@localhost IDENTIFIED BY '$db_pass';"
        Q3="GRANT ALL PRIVILEGES ON $name.* to $db_user@localhost identified by '$db_pass';"
        Q4="FLUSH PRIVILEGES;"
        SQL="${Q1}${Q2}${Q3}${Q4}"
        mysql -uroot -p$mysqlpass -e "$SQL"
    else
        echo "database $db_name does exist already"
    fi
}

function run_freedns {
    print_info "run freedns (dynamic IP)..."
    if [ -z "$freedns_key" ]
    then
        print_info "freedns was not started because 'freedns_key' is empty in $configfile"
    else
        if [ -n "$selfhost_user" ]
        then
            die "You can not use freeDNS AND selfHOST for dynamic IP updates ('freedns_key' AND 'selfhost_user' set in $configfile)"
        fi
        wget --no-check-certificate -O - http://freedns.afraid.org/dynamic/update.php?$freedns_key
    fi
}

function install_run_selfhost {
    print_info "install and start selfhost (dynamic IP)..."
    if [ -z "$selfhost_user" ]
    then
        print_info "selfHOST was not started because 'selfhost_user' is empty in $configfile"
    else
        if [ -n "$freedns_key" ]
        then
            die "You can not use freeDNS AND selfHOST for dynamic IP updates ('freedns_key' AND 'selfhost_user' set in $configfile)"
        fi
        if [ -z "$selfhost_pass" ]
        then
            die "selfHOST was not started because 'selfhost_pass' is empty in $configfile"
        fi
        if [ ! -d $selfhostdir ]
        then
            mkdir $selfhostdir
        fi
        # the old way
        # https://carol.selfhost.de/update?username=123456&password=supersafe
        #
        # the prefered way
        wget --output-document=$selfhostdir/$selfhostscript http://jonaspasche.de/selfhost-updater
        echo "router" > $selfhostdir/device
        echo "$selfhost_user" > $selfhostdir/user
        echo "$selfhost_pass" > $selfhostdir/pass
        bash $selfhostdir/$selfhostscript update
    fi
}

function ping_domain {
    print_info "ping domain $domain..."
    # Is the domain resolved? Try to ping 6 times à 10 seconds
    COUNTER=0
    for i in {1..6}
    do
        print_info "loop $i for ping -c 1 $domain ..."
        if ping -c 4 -W 1 $le_domain
        then
            print_info "$le_domain resolved"
            break
        else
            if [ $i -gt 5 ]
            then
                die "Failed to: ping -c 1 $domain not resolved"
            fi
        fi
        sleep 10
    done
    sleep 5
}

function configure_cron_freedns {
    print_info "configure cron for freedns..."
    if [ -z "$freedns_key" ]
    then
        print_info "freedns is not configured because freedns_key is empty in $configfile"
    else
        # Use cron for dynamich ip update
        #   - at reboot
        #   - every 30 minutes
        if [ -z "`grep 'freedns.afraid.org' /etc/crontab`" ]
        then
            echo "@reboot root http://freedns.afraid.org/dynamic/update.php?$freedns_key > /dev/null 2>&1" >> /etc/crontab
            echo "*/30 * * * * root wget --no-check-certificate -O - http://freedns.afraid.org/dynamic/update.php?$freedns_key > /dev/null 2>&1" >> /etc/crontab
        else
            print_info "cron for freedns was configured already"
        fi
    fi
}

function configure_cron_selfhost {
    print_info "configure cron for selfhost..."
    if [ -z "$selfhost_user" ]
    then
        print_info "selfhost is not configured because selfhost_key is empty in $configfile"
    else
        # Use cron for dynamich ip update
        #   - at reboot
        #   - every 5 minutes
        if [ -z "`grep 'selfhost-updater.sh' /etc/crontab`" ]
        then
            echo "@reboot root bash /etc/selfhost/selfhost-updater.sh update > /dev/null 2>&1" >> /etc/crontab
            echo "*/5 * * * * root /bin/bash /etc/selfhost/selfhost-updater.sh update > /dev/null 2>&1" >> /etc/crontab
        else
            print_info "cron for selfhost was configured already"
        fi
    fi
}

function install_letsencrypt {
    print_info "installing let's encrypt ..."
    # check if user gave domain
    if [ -z "$le_domain" ]
    then
        die "Failed to install let's encrypt: 'le_domain' is empty in $configfile"
    fi
    if [ -z "$le_email" ]
    then
        die "Failed to install let's encrypt: 'le_email' is empty in $configfile"
    fi
    nocheck_install "certbot python-certbot-apache"
    print_info "run certbot ..."
    certbot --apache -w $install_path -d $le_domain -m $le_email --agree-tos --non-interactive --redirect --hsts --uir
    service apache2 restart
}

function check_https {
    print_info "checking httpS > testing ..."
    url_https=https://$le_domain
    wget_output=$(wget -nv --spider --max-redirect 0 $url_https)
    if [ $? -ne 0 ]
    then
        print_warn "check not ok"
    else
        print_info "check ok"
    fi
}

function install_zotserver {
    print_info "installing addons..."
    cd $install_path
    util/add_addon_repo https://framagit.org/hubzilla/addons hzaddons
    mkdir -p "store/[data]/smarty3"
    # chmod -R 777 store
    touch .htconfig.php
    # The next run of $cron_job (daily-update script) will correct the permissions of the next line
    chmod ou+w .htconfig.php  
    cd /var/www/
    chown -R www-data:www-data $install_path
	chown root:www-data $install_path/
	chown root:www-data $install_path/.htaccess
	chmod 0644 $install_path/.htaccess
    print_info "installed addons"
}

function configure_cron_daily {
    print_info "configuring cron..."
    # every 10 min for poller.php
    if [ -z "`grep 'php Zotlabs/Daemon/Master.php' /etc/crontab`" ]
    then
        echo "*/10 * * * * www-data cd $install_path; php Zotlabs/Daemon/Master.php Cron >> /dev/null 2>&1" >> /etc/crontab
    fi
    # Run external script daily at 05:30
    # - stop apache/nginx and mysql-server
    # - renew the certificate of letsencrypt
    # - update repository core and addon
    # - update and upgrade linux
    # - reboot is done by "shutdown -h now" because "reboot" hangs sometimes depending on the system
    echo "#!/bin/sh" > /var/www/$cron_job
    echo "#" >> /var/www/$cron_job
    echo "echo \" \"" >> /var/www/$cron_job
    echo "echo \"+++ \$(date) +++\"" >> /var/www/$cron_job
    echo "echo \" \"" >> /var/www/$cron_job
    echo "echo \"\$(date) - stopping apache and mysql...\"" >> /var/www/$cron_job
    echo "service apache2 stop" >> /var/www/$cron_job
    echo "/etc/init.d/mysql stop # to avoid inconsistencies" >> /var/www/$cron_job
    echo "#" >> /var/www/$cron_job
    echo "echo \"\$(date) - renew certificate...\"" >> /var/www/$cron_job
    echo "certbot renew --noninteractive" >> /var/www/$cron_job
    echo "#" >> /var/www/$cron_job
    echo "echo \"\$(date) - db size...\"" >> /var/www/$cron_job
    echo "du -h /var/lib/mysql/ | grep mysql/" >> /var/www/$cron_job
    echo "#" >> /var/www/$cron_job    
    echo "# update of $le_domain Zot hub/instance" >> /var/www/$cron_job
    echo "echo \"\$(date) - updating core and addons...\"" >> /var/www/$cron_job
    echo "echo \"reaching git repository for $le_domain $zotserver hub/instance...\"" >> /var/www/$cron_job
    echo "(cd $install_path ; util/udall)" >> /var/www/$cron_job
    echo "chown -R www-data:www-data $install_path # make all accessible for the webserver" >> /var/www/$cron_job
    echo "chown root:www-data $install_path/.htaccess" >> /var/www/$cron_job
    echo "chmod 0644 $install_path/.htaccess # www-data can read but not write it" >> /var/www/$cron_job    
    echo "echo \"\$(date) - updating linux...\"" >> /var/www/$cron_job
    echo "apt-get -q -y update && apt-get -q -y dist-upgrade && apt-get -q -y autoremove # update linux and upgrade" >> /var/www/$cron_job
    echo "echo \"\$(date) - Update finished. Rebooting...\"" >> /var/www/$cron_job
    echo "#" >> /var/www/$cron_job
    echo "shutdown -r now" >> /var/www/$cron_job

    chmod a+x /var/www/$cron_job

    # If global cron job does not exist we add it to /etc/crontab
    if grep -q $cron_job /etc/crontab
    then
        echo "cron job already in /etc/crontab"
    else
        echo "30 05 * * * root /bin/bash /var/www/$cron_job >> /var/www/daily-updates.log 2>&1" >> /etc/crontab
        echo "0 0 1 * * root rm /var/www/daily-updates.log" >> /etc/crontab
    fi

    # This is active after either "reboot" or cron reload"
    systemctl restart cron
    print_info "configured cron for updates/upgrades"
}

########################################################################
# START OF PROGRAM
########################################################################
export PATH=/bin:/usr/bin:/sbin:/usr/sbin
check_sanity

print_info "We're installing a $zotserver instance"
install_path="$(dirname "$(pwd)")"

# Read config file edited by user
configfile=config.txt
source $configfile

selfhostdir=/etc/selfhost
selfhostscript=selfhost-updater.sh
cron_job="cron_job.sh"

#set -x    # activate debugging from here

zotserver=hubzilla
check_config
stop_zotserver
update_upgrade
install_curl
install_wget
install_sendmail
install_apache
install_imagemagick
install_php
install_composer
install_mysql
install_adminer
create_zotserver_db
run_freedns
install_run_selfhost
ping_domain
configure_cron_freedns
configure_cron_selfhost

if [ "$le_domain" != "localhost" ]
then
    install_letsencrypt
    check_https
else
    print_info "is localhost - skipped installation of letsencrypt and configuration of apache for https"
fi

install_zotserver

configure_cron_daily


#set +x    # stop debugging from here
