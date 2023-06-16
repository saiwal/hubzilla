
# How to use

## Disclaimers

- **This script does work with a fresh install of Debian 12 only**.
- Do not use if you have already installed and configured a webserver or sql server that was not installed by this script.

### Keep it Simple and Stupid

The script keeps everything as simple as possible (KISS):

- Apache as webserver (there is no choice to use another webserver like nginx)
- default PHP version of Debian
- one single Hubzilla intance only
- re-running the script does no harm

### When to use other Scripts

Use the scripts under [homeinstall](https://framagit.org/hubzilla/core/-/tree/master/.homeinstall)
if you look for more choices. The main differences are:

- Apache or nginx as webserver
- install multiple instances (domains) that run side by side on the server
- adds apache vhosts (instead of using the standard doc root /var/www/html)
- install PHP from https://packages.sury.org/php/ (instead of using the Debian repository)
- graphical installer whiptail
- The script stops (fails) if it finds results of a previous installation. (The [debian-setup.sh](https://framagit.org/ojrandom/core/-/tree/dev/.debianinstall) will just jump over it.)
- If something fails the script tries to clean up everything that was installed up to the point of failure. (That might cause trouble if certbot registered a certificate already.)
- The script under [homeinstall](https://framagit.org/hubzilla/core/-/tree/master/.homeinstall) seems to be an older version of the scripts used for Streams
  + [autoinstall](https://codeberg.org/streams/streams/src/branch/dev/contrib/autoinstall)
  + [easyinstall](https://codeberg.org/streams/streams/src/branch/dev/contrib/easyinstall)

## Preconditions

Hardware

+ internet connection and router at home
+ computer connected to your router (a Raspberry 3 will do for very small Hubs)

Software

+ fresh installation of Debian 12 (bookworm)
+ router with open ports 80 and 443 for your web server

You can of course run the script on a VPS or any distant server as long as the above sotfware requirements are satisfied.

## How to run the script

+ Register your own domain (for example at selfHOST) or a free subdomain (for example at freeDNS)
+ Log on to your fresh Debian
  - apt-get install git
  - mkdir -p /var/www
  - cd /var/www
  - git clone https://framagit.org/hubzilla/core.git html
  - cd html/.debianinstall
  - cp config.txt.template config.txt
  - nano config.txt
    - read the comments carefully
    - enter your values: db pass, domain
    - (optionally) Enter your values for dyn DNS
  - ./debian-setup.sh as root
    - ... wait, wait, wait until the script is finished
+ Open your domain with a browser and step throught the initial configuration of your hubzilla instance.
  - default database name = hubzilla
  - default dababase user = hubzilla

## Optional - Switch verification of email on/off

Do this just before you register the first user without email verification.

In a terminal

    su -
    cd /var/www/html

Check the current setting 

    util/config system verify_email

Switch the verification off

    util/config system verify_email 0

## What the script will do for you...

+ install everything required by your hubzilla instance, basically a web server (Apache), PHP, a database (MySQL), certbot,...
+ create a database
+ run certbot to have everything for a secure connection (httpS)
+ create a script for daily maintenance
  - renew certfificate (letsencrypt)
  - update of your hubzilla instance for core and addons (git)
  - update of Debian
  - restart
+ create cron jobs for
  - DynDNS (selfHOST.de or freedns.afraid.org) every 5 minutes
  - Master.php for your hubzilla instance every 10 minutes
  - daily maintenance script every day at 05:30

The script is known to work without adjustments with

+ Hardware
  - standard PC with Debian 12 (bookworm)
  - Raspberry 4 with Raspbian, Debian 12 (TODO: needs confirmation after swich to Debian12)
  - for tesing purposes: under localhost inside a virtual machine, [KVM](https://wiki.debian.org/KVM)
+ DynDNS
  - selfHOST.de
  - freedns.afraid.org

# Step-by-Step - some Details

## Preparations

## Configure your Router

Your webserver has to be visible in the internet.  

Open the ports 80 and 443 on your router for your Debian. Make sure your web server is marked as "exposed host".

## Preparations Dynamic IP Address

Follow the instructions in .debianinstall/config.txt.  

In short...  

Your Hubzilla server must be reachable by a domain that you can type in your browser

    cooldomain.org

You can use subdomains as well

    my.cooldomain.org

There are two ways to get a domain...

### Method 1: Buy a Domain 

...for example buy at selfHOST.de  

The cost is 1,50 € per month (2019).

### Method 2: Register a free subdomain

...for example register at freedns.afraid.org

## Note on Rasperry 

It is recommended to run the Raspi without graphical frontend (X-Server). Use...

    sudo raspi-config

to boot the Rapsi to the client console.

DO NOT FORGET TO CHANGE THE DEFAULT PASSWORD FOR USER PI!

## Reminder for Different Web Wervers

For those of you who feel adventurous enough to use a different web server (i.e. Lighttpd...), don't forget that this script will install Apache or Nginx and that you can only have one web server listening to ports 80 & 443. Also, don't forget to tweak your daily shell script in /var/www/ accordingly.
