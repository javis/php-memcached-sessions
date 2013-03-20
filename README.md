php-memcached-sessions
======================

A PHP session handler that uses memcached to store session with multiple servers, failover and replication support.

## Features

* Works with pecl/memcache and pecl/memcached client libraries
* Support 1 or more memcached servers configuration
* Support data replication
* Support failover
* Very configurable

## Usage

    <?php
    include('MemcachedSession.php');
    
    // set memcached session handler
    MemcachedSession::config(array(
        'lifetime'      => 0,     // session lifetime in seconds, by default session.gc_maxlifetime php config
        'random_read'   => true,  // when true the server chosen reading will be random, if not will try with the first in the list
        'replicate'     => true,  // will copy the same data in all servers
        'failover'      => true,  // if one fails then read from another server
        'servers'       => array( // server array list, port separated with ':'
            '127.0.0.1',
        ),
    ));

