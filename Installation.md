# Installation
This guide to install

## Prerequisites
- Docker and Docker Compose (API version 1.53 minimum) installed on the server and all nodes.
There is no version control, so you will have to check for new versions on here regularly.
- If using SSL and a domain, a reverse proxy is required. This guide shows how to use [ProxyDNSCache](https://github.com/mjdaws0n/ProxyDNSCache) which is reccommended, as it is built to work with chap.
- I tested with root access, however you may not need it.
- Minimum of 2 Open ports on the server and one on the node.

## Reverse Proxy
It's recommended to start with the reverse proxy, you can set this up however you like using nginx, apache or whatever else you would like to use. This guide uses [ProxyDNSCache](https://github.com/mjdaws0n/ProxyDNSCache).

This guide also assumes that your domain is on cloudlfare as it uses it's API to generate a certificate.

**THESE INTRUCTIONS SHOULD BE FOLLOWED ON THE SERVER AND EACH NODE**

ProxyDNSCache requries access to port 80, 443 and 441, these can be confirgured to different ports, but then it defeats the whole point and your domain would have to speciy a port afterwards.

If you already have website on hosted on things such as Nginx or Apach, you will have to edit the configs of them to not be using 80 or 443. I suggest just putting them on a random port such as 82, and then making them no SSL and then using ProxyDNSCache to act as a reverse proxy for them aswell.

### Download
Download the correct asset, from the latest version from https://github.com/MJDaws0n/ProxyDNSCache/releases/latest

If you system is not listed, you can build it yourself from the instructions on the github, x64 and macos is not fully tested and may require you to re-build it on your own server.

### Setup the certificates
If you already have them, you can igore this section, however it shows how to get wildcard certificates that auto renew. The whole point of ProxyDNSCache is that you don't need to edit the config every time you want a new subdomain and simply have to create an SRV record, so because of this, I suggest if you don't have a wildcard domain, then you follow these instructions.

You need sudo privileges for this.

#### Remove out of date certbot
Certbot if already installed is likley out of date, we want a specific version and this versionn will not do.
```sh
sudo apt-get remove certbot
sudo apt autoremove
```

#### Install dependancies
```sh
sudo apt update
sudo apt install python3 python3-dev python3-venv libaugeas-dev gcc
```

#### Create a virtual environment
```sh
sudo python3 -m venv /opt/certbot/
sudo /opt/certbot/bin/pip install --upgrade pip
```

#### Install cerbot
```sh
sudo /opt/certbot/bin/pip install certbot
```

#### Check certbot is installed
```sh
sudo ln -s /opt/certbot/bin/certbot /usr/local/bin/certbot
```

#### Setup auto renewal
```sh
echo "0 0,12 * * * root /opt/certbot/bin/python -c 'import random; import time; time.sleep(random.random() * 3600)' && sudo certbot renew -q" | sudo tee -a /etc/crontab > /dev/null
```

#### Install cloudflare plugin
```sh
source /opt/certbot/bin/activate
pip install certbot-dns-cloudflare
```
Exit by typing deactivate

#### Create a cloudlfare.ini file in a safe location
Generate a cloudlfare API key from the cloudflare dashboard
```ini
dns_cloudflare_api_token = KEY HERE
```

#### Set file permissions
Change the path to the correct path
```sh
sudo chmod 600 /path/to/cloudflare.ini
```

#### Generate cerificate
Ensure you change domain.com and *domain.com to the correct domain, and change the path to the correct path.
```sh
sudo certbot certonly --dns-cloudflare --dns-cloudflare-credentials /path/to/cloudflare.ini --preferred-challenges dns -d domain.com -d '*.domain.com'
```

### Setup systemd service
This allows it to always be running
#### Create unit file
```sh
sudo nano /etc/systemd/system/ProxyDNSCache.service
```
The contents should be like the following, obviously update the path correctly
```ini
[Unit]
Description=ProxyDNSCache
After=network.target

[Service]
WorkingDirectory=/home/ProxyDNSCache
ExecStart=/home/ProxyDNSCache/ProxyDNSCache-linux
Restart=always
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

Binding to ports 80/443 typically requires root or capabilities. If you run as non-root, you can grant the binary permission to bind privileged ports. Change the path correctly. If you are root you don't need this.
```sh
sudo setcap 'cap_net_bind_service=+ep' /home/ProxyDNSCache/ProxyDNSCache-linux
```

#### Enable and start
```sh
sudo systemctl daemon-reload
sudo systemctl enable --now ProxyDNSCache
```

View logs if needed
```sh
sudo journalctl -u ProxyDNSCache -f
```

### Set the config
Go to the location of your ProxyDNSCache executable and set the config as follows obviously chaning example.com to your domain and the cert to the correct certificate path.
```yml
certs:
    - "example.com":
            - cert: "/etc/letsencrypt/live/example.com/fullchain.pem"
                key: "/etc/letsencrypt/live/example.com/privkey.pem"
    - "*.example.com":
            - cert: "/etc/letsencrypt/live/example.com/fullchain.pem"
                key: "/etc/letsencrypt/live/example.com/privkey.pem"
```

### Create DNS records
You need an `A` record pointing to the chap server that will be the main domain to access. Such as `chap.example.com`.

You need an `A` record pointing to the chap server that will be the main websocket the nodes use to communicate. Such as `chap-ws.example.com`.

You need an `A` record pointing to each chap node. Such as `chap-node-1.example.com`.

You need an `SRV` record with the port as the port that you want chap to run on (can be whatever you want), the target as `localhost` and the name as `_pdcache._tcp.chap.example.com` (changing chap.example.com to be the same as the first `A` record).

You need an `SRV` record with the port as the port that you want chap server to run on (can be whatever you want), the target as `localhost` and the name as `_pdcache._tcp.chap.example.com` (changing chap.example.com to be the same as the first `A` record).

You need an `SRV` record with the port as the port that you want chap server's websocket to run on (can be whatever you want), the target as `localhost` and the name as `_pdcache._tcp.chap-ws.example.com` (changing chap-ws.example.com to be the same as the second `A` record).

You need an `SRV` record on each chap node with the port as the port that you want the chap node's websocket to run on (can be whatever you want), the target as `localhost` and the name as `_pdcache._tcp.chap-node-1.example.com` (changing chap-node-1.example.com to be the same as the third `A` record).

## Installing Chap Server
Chap server is the web panel interface.

### Downloading
#### Clone the repo
```sh
cd ~/
git clone https://github.com/MJDaws0n/chap.git
mv chap chap-server
cd chap-server
```

### Settings
#### Copy and edit .env file
```sh
cp .env.example .env
nano .env
```

#### Change the following
| Value | Description |
| - | - |
| APP_URL | Change this to the URL you set earlier when setting up the reverse proxy. |
| APP_SECRET | Change this to a secure random string. Keep this safe and DO NOT SHARE IT! |
| APP_PORT | Change this to the port that you set you reverse proxy to run the main chap server on. The web panel one. |
| WS_PORT | Change this to the port that you set you reverse proxy to run the main chap server's websocket on. |
| DB_PASSWORD | Set this to a random secure string. |
| DB_ROOT_PASSWORD | Set this to a random secure string. |
| CAPTCHA_PROVIDER | Set this as `none`, `recaptcha` or `autogate` depending on what human verification you want to use. Also set the values just bellow that appropriately. \* |

\* I'm currently trialing autogate and it would be great if people could test it. To help me out, go to me website and fill out the contact form and i'll give you free access to autogate's human verification. Autogate is a SAAS not open source software.

### Start and build
#### Start and build using this
```sh
docker compose -f docker-compose.server.yml up --build
```
#### Once confirmed no errors you can run in detached mode
```sh
docker compose -f docker-compose.server.yml up -d
```

### Does it work?
Once installed, you can now login using:
`admin@chap.dev`
`password`

Ensure you change the email and password, or create a new admin account and delete the old one.

## Installing Chap Node
Chap node is everywhere you want to run you docker containers on.

### Ensure running correct version of docker
```sh
docker version
```
You are looking for where it says API version 1.** under Server : Docker engine. Ensure it is at least 1.53.

### Setup the node
On your chap panel go to nodes and add a node.
Set a port range, you would like to allow to be auto generated.

Take a note of the `NODE_TOKEN` and the `NODE_ID`. Do not share your NODE_TOKEN with anyone. It would allow your node to be hacked.

### Downloading
#### Clone the repo
```sh
cd ~/
git clone https://github.com/MJDaws0n/chap.git
mv chap chap-node
cd chap-node
```

### Settings
#### Copy and edit .env file
```sh
cp node/.env.example .env
nano .env
```

#### Change the following
| Value | Description |
| - | - |
| NODE_ID | This should be the node id we talked about before. |
| NODE_TOKEN | This should be the node token we talked about before. |
| CHAP_SERVER_URL | This should be the websocket communication between the node to server e.g. `chap-ws.example.com` |
| BROWSER_WS_PORT | This is the port that you set in the reverse proxy. |

### Start and build
#### Start and build using this
```sh
docker compose -f docker-compose.node.yml up --build
```
#### Once confirmed no errors you can run in detached mode
```sh
docker compose -f docker-compose.node.yml up -d
```
### Does it work?
To check it works, you should now see that chap shows the node as online.

## Update
You can the node by doing this assuming it's at in the folder ~/chap-node.
```sh
git clone https://github.com/MJDaws0n/chap.git ~/temp && rsync -a --exclude='.env' ~/temp/ ~/chap-node/ && rm -rf ~/temp
```

### Restart and build with:
```sh
cd ~/chap-node/
docker compose -f docker-compose.node.yml up --build
```
### Exit it then start again using
```sh
docker compose -f docker-compose.node.yml up -d
```

And for the server update using this assuming it's at ~/chap-server.
```sh
git clone https://github.com/MJDaws0n/chap.git ~/temp && rsync -a --exclude='.env' ~/temp/ ~/chap-server/ && rm -rf ~/temp
```

### Restart and build
```sh
cd ~/chap-server/
docker compose -f docker-compose.server.yml up --build
```
### Exit it then start again using
```sh
docker compose -f docker-compose.server.yml up -d
```