<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Behind a load balancer or reverse proxy every request arrives from the
    | proxy's address, and the client's is in X-Forwarded-For. The header is
    | only read when the request comes from one of these addresses; otherwise
    | every client would share the proxy's address, and with it the rate
    | limits that count per address.
    |
    | A comma-separated list of addresses or CIDR ranges, or `*` to trust
    | whichever address connects (only when nothing but the proxy can reach
    | the service). The default is the private and loopback ranges, which
    | covers a load balancer in the same network and a proxy on the same host.
    | Set it to an address that never connects (e.g. 192.0.2.1) to trust none.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8'),

];
