class MemcachedSession
{   
    private static $config = array(
        'lifetime'      => 0,
        'random_read'   => true,
        'replicate'     => true, // will copy the same data in all servers
        'failover'      => true, // if one fails then read from another server
        'servers'       => array(
            '127.0.0.1',
        )
    );
    
    private static $connections = array();
    
    public static function config($config){
        self::$config = array_merge(self::$config,$config);
        session_set_save_handler(
                array('MemcacheSession', 'open'),
                array('MemcacheSession', 'close'),
                array('MemcacheSession', 'read'),
                array('MemcacheSession', 'write'),
                array('MemcacheSession', 'destroy'),
                array('MemcacheSession', 'gc')
        );
    }

    public static function open(){ 
        if (self::$config['lifetime']==0)
        self::$config['lifetime'] = ini_get('session.gc_maxlifetime');
        
        // the following prevents unexpected effects when using objects as save handlers
        register_shutdown_function('session_write_close');
        
        return true; 
    } 
    
    public static function read($id){
        
        // if we're not replicating data we won't randomize because the first 
        // configured server must be the first try to read 
        if (self::$config['replicate'] and self::$config['random_read']){
            
            // randomize servers order
            shuffle(self::$config['servers']);
            
        }
        
        //echo '<pre>';
        //var_dump(self::$config['servers']);
        //echo '</pre>';
        
        // try with one server by time
        foreach (self::$config['servers'] as $ip){
            //echo "<p>read $ip</p>";
            if (class_exists('Memcached')){                
                if (!array_key_exists($ip,self::$connections)){ // non existing connection, creating a new one for this ip
                    self::$connections[$ip] = new Memcached();                    
                    self::$connections[$ip]->addServer($ip, 11211);
                }
                
                if(self::$connections[$ip] != false) { 
                    $value = self::$connections[$ip]->get("sessions/{$id}"); // get value
                
                    $res = self::$connections[$ip]->getResultCode(); // will check result
                    if ($res == Memcached::RES_NOTFOUND) //key does not exists
                        return null;
                    elseif ($res == 0) // value found
                        return $value; // return
                    else{ 
                        self::$connections[$ip] = false; // error or timeout (try with the next server)
                    }
                }                
                // unavailable server, skipping
                
            }
            else{
                if (!array_key_exists($ip,self::$connections)){ 
                    self::$connections[$ip] = new Memcache();
                    if (!self::$connections[$ip]->connect($ip, 11211)){ //try connection
                        self::$connections[$ip] = false; // mark server as down, skipping
                    }
                }
                
                if(self::$connections[$ip] != false){
                    $value = self::$connections[$ip]->get("sessions/{$id}");
                    if ($value !== false) return unserialize($value);
                    else return null; // value not found (false also could mean server error, but we'll assume key does not exists)
                }
                // server down, skipping
            }
            
            // without failover we won't keep trying
            if (!self::$config['failover']) return null;
        }
        
        return null; // no servers availables
    } 
    
    public static function write($id, $data){ 
        // try with one server by time
        foreach (self::$config['servers'] as $ip){
            $wrote = false;
            //echo "<p>write $ip</p>";
            if (class_exists('Memcached')){
                if (!array_key_exists($ip,self::$connections)){  // non existing connection, creating a new one for this ip
                    self::$connections[$ip] = new Memcached();                    
                    self::$connections[$ip]->addServer($ip, 11211);
                }
                
                if( self::$connections[$ip] != false ) { 
                    if (!self::$connections[$ip]->set("sessions/{$id}", $data, self::$config['lifetime'])){
                        self::$connections[$ip] = false; // error or timeout
                    }
                    else{
                        $wrote = true;
                    }
                }
                // unavailable server, skipping
            }
            else{
                if (!array_key_exists($ip,self::$connections)){ 
                    self::$connections[$ip] = new Memcache();
                    if (!self::$connections[$ip]->connect($ip, 11211)){ //try connection
                        self::$connections[$ip] = false; // mark server as down, skipping   
                    }
                }
                
                if(self::$connections[$ip] != false){
                    if (!self::$connections[$ip]->set("sessions/{$id}", serialize($data), 0, self::$config['lifetime'])){
                        self::$connections[$ip] = false; // error or timeout, mark server as down
                    }
                    else{
                        $wrote = true;
                    }
                }
                // unavailable server, skipping
            }
            
            // if we couldn't write and we do failover we'll try to write in the next server
            // also we'll write in the next server if we're replicating
            if (!((!$wrote and self::$config['failover']) or self::$config['replicate'])) break;
        }
        return true;
    } 
    
    public static function destroy($id){ 
        // try with one server by time
        foreach (self::$config['servers'] as $ip){
            if (class_exists('Memcached')){
                if (!array_key_exists($ip,self::$connections)){ // non existing connection, creating a new one for this ip
                    self::$connections[$ip] = new Memcached();                    
                    self::$connections[$ip]->addServer($ip, 11211);
                }
                
                if(!self::$connections[$ip] == false) {

                    $value = self::$connections[$ip]->delete("sessions/{$id}"); // get value

                    if ($value==false){
                        $res = self::$connections[$ip]->getResultCode(); // will check result
                        if ($res != Memcached::RES_NOTFOUND){ 
                            self::$connections[$ip] = false; // error or timeout (try with the next server)
                        }
                    }
                }
                // unavailable server, skipping
            }
            else{
                if (!array_key_exists($ip,self::$connections)){ 
                    self::$connections[$ip] = new Memcache();
                    if (!self::$connections[$ip]->connect($ip, 11211)){ //try connection
                        self::$connections[$ip] = false; // mark server as down, skipping
                    }
                }
                
                if(!self::$connections[$ip] == false){
                    self::$connections[$ip]->delete("sessions/{$id}");
                }
            }
            
            // no need to continue if we are not replicating and we don't do failover
            if (!self::$config['replicate'] and !self::$config['failover']) break;
        }
        return true;
    } 
    
    
    public static function gc(){ return true; } 
    public static function close(){  return true; }
} 
