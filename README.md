PHP Proxmox Backups
===================

Simple tool for easing the execution of VMs and CTs backups with Proxmox 5 environment (provided by OVH).

Apart from starting backups of defined machines allows to send notification emails (SMTP required) or Telegram messages
using a Telegram Bot.

FEATURES
========

- init of backups building using internal proxmox tools
- storing on FTPs and keeping a backlog on N backups
- sending notification emails
- sending Telegram notifs

TODOs
=====

- schedule of backups
- different rules for backlog: to keep weekly, monthly and not only daily entries
- provide better readme
- different FTPs for different machines

Installation
============

# Download

Download ZIP package or clone using git:
```bash
git clone git@github.com:ideaconnect/php-proxmox-backups.git
```

or install using Composer:
```bash
composer require idct/php-proxmox-backups
```

# Install

Install using Composer:
```bash
composer install
```

This will create the `vendors` folder with all required libraries.

Create __config.json__ file (check __configuration__ section below and __config_sample.json__).

Execute the tool:
```bash
cd bin/
./dobackups
```

In case of errors try to execute using php directly:
```bash
cd bin/
php dobackups
```

You can also add this to cron - for example to execute at 23:00 each day:
```
0 23 * * * cd [path to your application]/bin && php dobackups > /var/log/proxmox-backups.log 2>&1
```

Configuration
=============

Create __config.json__ file with two objects: __global__, __machines__ (array).

For example:
```json
{
    "global": {},
    "machines: []
}
```

If you want FTP upload support define `ftp` object in the `global` section - for example:
```json
{
    "global": {
        "ftp": {
            "login": "sample-username",
            "pass": "your-password",
            "dir": "/backups/",
            "host": "ftpbackups.some.host.net"
        }
    },
    "machines: []
}
```

Machine must have `ftp.backlog` value defined to use the `ftp` feature.

If you want to use email notifications feature then please add `smtp` object into the `global` section - for instance:
```json
{
    "global": {
        "smtp": {
            "host": "smtp.your.host.net",
            "username": "smtp_username",
            "password": "smtp_password",
            "port": 25,
            "from_mail": "backups@yourname.pl",
            "from_name": "My Best Backups"
        },
        "ftp": {
            "login": "sample-username",
            "pass": "your-password",
            "dir": "/backups/",
            "host": "ftpbackups.some.host.net"
        }
    },
    "machines: []
}
```

The most important part is to provide information about VMs or CTs which are meant to be included in the process.
Sample block:
```json
        {
            "id": 101,
            "storage": "backups",
            "email": "you@youremail.com",
            "storage_path": "/var/lib/vz/backups/global/dump/",
            "ftp.backlog": 3,
            "telegram": {
                "bot": "<telegram bot id>",
                "channel": "<telegram channel id>"
            }
        }
```

* id -> identifier of the VM or CT from Proxmox
* storage -> identifier of the storage from Proxmox to which the particular VM (or CT) can perform backups
* storage_path -> path to the storage defined in the `storage` argument; *this will be removed in the future, have not
found yet how to extract path by name*
* email -> optional, if set then email notifications about the process will be sent (if SMTP is provided)
* telegram -> optional, allows sending simple notifications using a Telegram Bot.

You can provide more than one machine (VM or CT).

Contribution
============

I more than appreciate any contribution: please provide it using pull requests or issues reporting. Be sure to follow
PSR rules when contributing any code!
