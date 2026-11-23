### **1. Variants (Ieteicamais: Izolēts Proxy konteinerā — 100% drošs)**

Šis variants garantē, ka servera noklusējuma tīkls paliek neskarts, un uz `127.0.0.1:8888` tiek pacelts HTTP proxy.

#### **1. solis: Uzstādi Docker (ja vēl nav uzstādīts)**

Pieslēdzies kā `root` un izpildi:

```
bash

apt update && apt install -y docker.io

systemctl enable --now docker
```

#### **2. solis: Sagatavo mapi un failus**

```
bash

mkdir -p /root/vpn

cd /root/vpn
```

Iekopē šajā mapē savu `.ovpn` failu ar nosaukumu `client.ovpn`.

Ja VPN prasa lietotājvārdu un paroli, izveido failu `/root/vpn/auth.txt`:

```
bash

cat \<\< 'EOF' \> /root/vpn/auth.txt

mans\_lietotajvards

mana\_parole

EOF

chmod 600 /root/vpn/auth.txt
```

#### **3. solis: Palaid proxy servisu**

Izpildi vienu komandu:

```
bash

docker run -d \\

  --name vpn-proxy \\

  --restart always \\

  --cap-add=NET\_ADMIN \\

  --device /dev/net/tun \\

  -p 127.0.0.1:8888:8888/tcp \\

  -v /root/vpn/client.ovpn:/gluetun/custom.ovpn:ro \\

  -v /root/vpn/auth.txt:/gluetun/auth.txt:ro \\

  -e VPN\_SERVICE\_PROVIDER=custom \\

  -e OPENVPN\_CUSTOM\_CONFIG=/gluetun/custom.ovpn \\

  -e OPENVPN\_USER="$(head -n 1 /root/vpn/auth.txt)" \\

  -e OPENVPN\_PASSWORD="$(tail -n 1 /root/vpn/auth.txt)" \\

  qmcgaw/gluetun
```

*(Piezīme: ja `.ovpn` failā autorizācija ir ar sertifikātiem un parole nav vajadzīga, divas pēdējās `-e OPENVPN\_...` un `auth.txt` rindiņas vienkārši izlaid).*

#### **4. solis: Pārbaudi, vai proxy strādā un IP ir nomainījusies**

Pārbaudi servera parasto IP:

```
bash

curl https://api.ipify.org

\# Parādīs Hetzner IP (65.21.182.7)
```

Pārbaudi caur jauno proxy:

```
bash

curl -x http://127.0.0.1:8888 https://api.ipify.org

\# Parādīs Tava VPN piešķirto IP adresi!
```

#### **5. solis: Ieslēdz skreiperiem `.env` failā**

Atver `/var/www/sodiena.lv/.env` un pievieno:

```
env

SCRAPER\_PROXY=http://127.0.0.1:8888
```

Saglabā un izpildi:

```
bash

cd /var/www/sodiena.lv && php artisan config:clear
```

Gatavs! Skreiperi automātiski izmantos VPN tuneli.

