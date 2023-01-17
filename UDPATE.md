# Update to Service.d Version

- List all containers
  - `docker ps`
- stop all DDE-System Container (dde_*)
  - `docker stop dde_dnsmasq dde_mailhog dde_mariadb dde_reverseproxy`
- remove all DDE-System Container (dde_*)
  - `docker rm dde_dnsmasq dde_mailhog dde_mariadb dde_reverseproxy`
- enable/disable needed DDE-System Container
  - list available service `dde system:services:available`
  - list enabled service `dde system:services:enabled`
  - enable service `dde system:services:enable mailhog`
  - disable service `dde system:services:disable mailhog`
- Start DDE-System Services `dde system:up`    

## Enable Standard DDE-System Services
```
dde system:services:enable dnsmasq
dde system:services:enable mailhog
dde system:services:enable mariadb
dde system:services:enable reverseproxy
```



