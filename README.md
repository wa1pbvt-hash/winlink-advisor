# Winlink Propagation Advisor

**Version 1.0.0**

A WSPR-based propagation dashboard for DigiPi that uses recent WSPR reception reports and Winlink gateway information to help identify potentially useful HF Winlink paths.

The Advisor is designed to run on a Raspberry Pi/DigiPi with a standard web server and Python 3.

## What It Does

Winlink Propagation Advisor combines:

- WSPR reception reports from PSKReporter
- Recent receiver and signal-strength information
- HF band selection
- Winlink HF gateway status
- VARA and ARDOP gateway information
- Broad propagation regions
- Freshness-aware recommendations
- A browser-based DigiPi web interface

The Advisor emphasizes **recent propagation evidence** rather than relying only on static distance calculations.
## What It Does Not Install

The basic Advisor installer does **not** install, configure, start, stop, or modify:

- PAT
- Mercury
- VARA HF
- ARDOP
- Hamlib
- Your radio
- WSPR/WSJT-X
- DigiPi's existing radio configuration

This makes the Advisor suitable for installation on an existing DigiPi without changing the radio stack.

## Requirements

A normal DigiPi installation should already provide:

- Raspberry Pi / DigiPi
- Python 3
- PHP
- /home/pi/localize.env
- A working DigiPi web server

The installer expects the standard DigiPi configuration file:

    /home/pi/localize.env

with at least:

    NEWCALL=YOURCALL
    NEWGRID=YOURGRID

If WINLINK_KEY is present, the Advisor can use it for Winlink gateway status information.

The installer never displays the actual Winlink key.

## Installation

Clone or copy this repository onto the DigiPi.

From the project directory:

    sudo ./install.sh

The installer will:

1. Check the DigiPi configuration.
2. Check required software.
3. Validate the Advisor Python code.
4. Back up an existing Advisor installation.
5. Install the Advisor engine.
6. Install the web interface.
7. Install the help document.
8. Run regional classification self-tests.
9. Check PHP syntax.
10. Verify the installed files.

Existing installations are backed up under:

    /home/pi/winlink-advisor/backups/

Each backup receives a timestamped directory.

## Web Interface

After installation, open the DigiPi web server and select the Winlink Propagation Advisor page.

The page automatically reads the station callsign from:

    /home/pi/localize.env

The callsign is therefore specific to the DigiPi on which the Advisor is installed. It is not stored in the GitHub package.

## WSPR Data

The Advisor uses recent WSPR reception reports.

The default freshness window is approximately **30 minutes**. If no suitable WSPR reports have been received recently, the Advisor may correctly show that there is no fresh propagation data.

This is intentional.

Running WSPR and allowing reports to reach the WSPR/PSKReporter systems provides the propagation observations used by the Advisor.

## HF Bands

The Advisor supports:

- 160m
- 80m
- 40m
- 30m
- 20m
- 17m
- 15m
- 12m
- 10m

The interface can use automatic band selection or a specific band.

## Propagation Regions

North American stations are grouped into three broad regions:

- **West**
- **Central**
- **East**

The boundaries are based primarily on longitude and are inspired by broad North American time-zone geography rather than exact political or time-zone boundaries.

European and African areas are represented separately as:

- **Europe / Atlantic**
- **Europe / Africa**

These regions are intended to provide useful propagation groupings rather than precise geographic boundaries.

## Scoring

The Advisor considers factors including:

- Number of unique WSPR receivers
- Signal strength
- Report freshness
- Geographic distribution of receivers
- Available Winlink gateway information

Fresh propagation observations receive more weight than older observations.

WSPR reception is treated as propagation evidence. It does not guarantee that a particular Winlink connection will succeed.

## Winlink Gateway Information

When `WINLINK_KEY` is configured, the Advisor can retrieve current Winlink HF gateway information and distinguish between:

- VARA HF gateways
- ARDOP HF gateways

Gateway information is combined with the WSPR propagation observations to provide the recommendations shown by the web interface.

## Optional PAT Integration

Some DigiPi installations may already have the DigiPi PAT controller:

    /usr/local/bin/digipi-connect-pat.sh

When that controller is present, the Advisor can provide the existing **Connect to PAT** functionality.

PAT integration is optional.

The basic Advisor installer does not install or configure the PAT controller, Mercury, VARA, ARDOP, or the radio.

A DigiPi without PAT can still use the propagation Advisor normally.

## Updating

Run the installer again after obtaining a newer version of the project:

    sudo ./install.sh

The installer is designed to be repeatable.

Before replacing an existing installation, it creates a timestamped backup.

## Restoring a Previous Version

Backups are stored under:

    /home/pi/winlink-advisor/backups/

Each backup directory contains the files replaced by the installer.

A backup can be manually restored if necessary.

Do not delete a backup until the new installation has been tested.

## Troubleshooting

### No fresh WSPR data

If the Advisor reports no fresh data:

1. Check that WSPR is running.
2. Check that your station is transmitting.
3. Allow reports to reach the WSPR/PSKReporter systems.
4. Check the selected band.
5. Remember that the Advisor uses a limited freshness window.

No fresh data does not necessarily indicate a software problem.

### Winlink gateway information unavailable

Check that:

    WINLINK_KEY

is present in:

    /home/pi/localize.env

The key should not be placed in the GitHub repository.

### PAT button unavailable

PAT integration is optional.

Check whether the DigiPi has:

    /usr/local/bin/digipi-connect-pat.sh

and whether it is executable.

The Advisor itself does not install that controller.

### PHP syntax check

The installed PHP file can be checked with:

    php -l /var/www/html/winlink-advisor.php

### Python syntax check

The installed Advisor engine can be checked with:

    python3 -m py_compile /home/pi/winlink-advisor/advisor.py

## Files Installed

The installer places:

    /home/pi/winlink-advisor/advisor.py

and:

    /var/www/html/winlink-advisor.php
    /var/www/html/winlink-advisor-help.txt

Backups are stored beneath:

    /home/pi/winlink-advisor/backups/

## Security and Configuration

Do not commit `/home/pi/localize.env` to GitHub.

The repository `.gitignore` excludes local environment/configuration files and runtime artifacts.

In particular, do not publish:

    WINLINK_KEY

The installer only reports whether the key is present.

## Project Status

Version 1.0.0 represents the first packaged release of the Winlink Propagation Advisor with:

- WSPR/PSKReporter propagation data
- Winlink gateway status
- Broad regional classification
- Freshness-aware propagation scoring
- DigiPi web interface
- Portable station identity
- Installation backups
- Installer self-tests
- Optional PAT integration detection

## Author

Created by **Peter Busha — WA1PB** for amateur-radio experimentation, education, and practical DigiPi use.

## License

Copyright 2026 Peter Busha.

This project is licensed under the MIT License. See `LICENSE` for the full license text.
