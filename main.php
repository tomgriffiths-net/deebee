<?php
class deebee{
    private static array $bees = [];
    private static array $cache = [];
    private static array $updates = [];
    private static array $definitions = [
        "get" => [
            "args" => ["string","string","boolean"],
            "defaults" => [2=>false]
        ],
        "update" => [
            "args" => ["string","string","mixed"],
            "defaults" => []
        ],
        "increment" => [
            "args" => ["string","string","integer|double"],
            "defaults" => [2=>1]
        ],
        "inArray" => [
            "args" => ["string","string","mixed","boolean"],
            "defaults" => [3=>false]
        ],
        "count" => [
            "args" => ["string","string"],
            "defaults" => []
        ],
        "doThings" => [
            "args" => ["string","array"],
            "defaults" => []
        ],
        "removeFromList" => [
            "args" => ["string","string","mixed","boolean","boolean"],
            "defaults" => [3=>false, 4=>false]
        ],
        "addToList" => [
            "args" => ["string","string","mixed","boolean"],
            "defaults" => [3=>false]
        ],
        "isset" => [
            "args" => ["string","string","boolean"],
            "defaults" => [2=>false]
        ],
        "listItems" => [
            "args" => ["string","string"],
            "defaults" => []
        ],
        "top" => [
            "args" => ["string","string","string","array","integer","boolean"],
            "defaults" => [2=>"anything", 3=>[], 4=>50, 5=>false]
        ],
        "search" => [
            "args" => ["string","string","string","array"],
            "defaults" => [2=>"anything", 3=>[]]
        ],
        "newItem" => [
            "args" => ["string","string","mixed","boolean","boolean"],
            "defaults" => [2=>null, 3=>true, 4=>true]
        ],
        "set" => [
            "args" => ["string","string","mixed"],
            "defaults" => []
        ],
        "remove" => [
            "args" => ["string","string"],
            "defaults" => []
        ]
    ];

    public static function command($line):void{
        $line = cli::parseLine($line);
    }
    //Startup stuff
    public static function init():void{
        if(!extensions::ensure("sqlite3")){
            mklog(2, "SQLite3 extension is not enabled, an attempt has been made to enable it, please restart for the change to take effect.");
        }

        $defaultSettings = [
            'hive' => 'thehive',
            'bees' => [
                'default' => [
                    'whitelist' => [],
                    'seperator' => '/'
                ]
            ],
        ];

        foreach($defaultSettings as $name => $value){
            if(!settings::isset($name)){
                if(!settings::set($name, $value)){
                    mklog(2, "Failed to set default setting " . $name);
                }
            }
        }

        files::ensureFolder(settings::read("hive") ?? "thehive");
    }
    public static function start():bool{
        $bees = settings::read('bees');
        if(!is_array($bees)){
            mklog(3, "The bees are not there");
            return false;
        }

        $hive = settings::read('hive');
        if(!is_string($hive) || empty($hive)){
            mklog(3, "The hive is not there");
            return false;
        }

        $loaded = 0;
        foreach($bees as $bee => $beeInfo){
            mklog(1, "Loading bee $bee");

            try{
                $db = new SQLite3("$hive/$bee.sqlite");
            }
            catch(Exception){
                mklog(3, "Failed to load bee $bee");
                continue;
            }

            $db->exec('PRAGMA journal_mode = WAL');
            $db->exec('PRAGMA synchronous = NORMAL');
            $db->exec('CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT)');

            //Read everything for loading into cache
            $result = $db->query('SELECT key, value FROM kv');
            if($result === false){
                mklog(3, "Failed to read data from bee $bee");
                continue;
            }

            self::$cache[$bee] = [];
            while($row = $result->fetchArray(SQLITE3_ASSOC)){
                self::$cache[$bee][$row['key']] = json_decode($row['value'], true);
            }
            self::$updates[$bee] = [];

            self::$bees[$bee] = [
                'con' => $db,
                'whitelist' => $beeInfo['whitelist'] ?? [],
                'seperator' => (empty($beeInfo['seperator'] ?? "") ? "/" : $beeInfo['seperator']),
            ];

            $loaded++;
        }

        return $loaded > 0;
    }
    //Loop through entire cache stuff
    public static function newItem(string $bee, string $prefix, mixed $value=null, bool $allowDashesAndUnderscores=true, bool $allowCapitals=true):?string{
        if(!isset(self::$bees[$bee])){
            return null;
        }

        if(array_key_exists($prefix, self::$cache[$bee]) || self::checkAncestor($bee, $prefix, false)){
            return null;
        }

        $prefix .= self::$bees[$bee]['seperator'];

        $i = 20;
        while($i < 200){
            $randomId = $prefix . math::randomString(max(round($i/5),5), $allowDashesAndUnderscores, $allowCapitals);
            $randomIdSep = $randomId . self::$bees[$bee]['seperator'];
            $i++;

            $found = false;
            foreach(self::$cache[$bee] as $existingKey => $_){
                //Check for exact match then for children
                if($existingKey === $randomId || str_starts_with($existingKey, $randomIdSep)){
                    $found = true;
                    break;
                }
            }
            if($found){
                continue;
            }

            self::setCombo($bee, $randomId, $value);

            return $randomId;
        }

        return null;
    }
    public static function listItems(string $bee, string $key):?array{
        if(!isset(self::$bees[$bee])){
            return null;
        }

        $key .= self::$bees[$bee]['seperator'];
        $keyLen = strlen($key);
        $children = [];

        foreach(self::$cache[$bee] as $existingKey => $_){
            if(!str_starts_with($existingKey, $key)){
                continue;
            }
            $relative = substr($existingKey, $keyLen);
            $firstSegment = explode(self::$bees[$bee]['seperator'], $relative, 2)[0];
            $children[$firstSegment] = true;
        }
        return array_keys($children);
    }
    public static function top(string $bee, string $keyPattern, string $mode="anything", array $args=[], int $limit=50, bool $ascending=false):?array{
        if($mode === "listContains"){
            return null;
        }
    
        $matches = self::search($bee, $keyPattern, $mode, $args);
        if(!is_array($matches)){
            return null;
        }

        $ascending ? asort($matches) : arsort($matches);

        return array_slice($matches, 0, $limit, true);
    }
    public static function search(string $bee, string $keyPattern, string $mode="anything", array $args=[]):?array{
        if(!isset(self::$bees[$bee])){
            return null;
        }

        if(in_array($mode, ["contains","startsWith","endsWith"])){
            if(!is_string($args[0] ?? null)){
                return null;
            }

            if(array_key_exists(1, $args)){
                if(!is_bool($args[1])){
                    return null;
                }
            }
            else{
                $args[1] = false;
            }

            //If lowercase requested, convert substring to lowercase for function.
            if($args[1]){
                $args[0] = strtolower($args[0]);
            }
        }
        elseif($mode === "listContains"){
            if(!array_key_exists(0, $args)){
                return null;
            }
        }
        elseif(in_array($mode, ["greaterThan","lessThan","between"])){
            if(!is_int($args[0] ?? null) && !is_float($args[0] ?? null)){
                return null;
            }

            if($mode === "between"){
                if(!is_int($args[1] ?? null) && !is_float($args[1] ?? null)){
                    return null;
                }
            }
        }
        elseif($mode !== "anything"){
            //Unknown mode
            return null;
        }

        $modes = [
            'contains' => function($value, $substring, $lowerCase):bool{
                if(!is_string($value)){return false;}
                if($lowerCase){$value = strtolower($value);}
                return str_contains($value, $substring);
            },
            'startsWith' => function($value, $substring, $lowerCase):bool{
                if(!is_string($value)){return false;}
                if($lowerCase){$value = strtolower($value);}
                return str_starts_with($value, $substring);
            },
            'endsWith' => function($value, $substring, $lowerCase):bool{
                if(!is_string($value)){return false;}
                if($lowerCase){$value = strtolower($value);}
                return str_ends_with($value, $substring);
            },
            'listContains' => function($value, $thing):bool{
                return is_array($value) && in_array($thing, $value);
            },
            'greaterThan' => function($value, $number):bool{
                return is_numeric($value) && $number < $value;
            },
            'lessThan' => function($value, $number):bool{
                return is_numeric($value) && $number > $value;
            },
            'between' => function($value, $min, $max):bool{
                return is_numeric($value) && $min < $value && $value < $max;
            }
        ];

        $sepQuoted = preg_quote(self::$bees[$bee]['seperator'], '/');
        // preg_quote turns literal '*' into '\*' - swap that back into a
        // "match anything except the separator" wildcard
        $regex = '/^' . str_replace('\*', "[^{$sepQuoted}]*", preg_quote($keyPattern, '/')) . '$/';
 
        $matches = [];
        foreach(self::$cache[$bee] as $key => $value){
            if(!preg_match($regex, $key)){
                continue;
            }
            
            if($mode !== "anything"){
                if(!$modes[$mode]($value, ...$args)){
                    continue;
                }
            }

            $matches[$key] = $value;
        }
        return $matches;
    }
    public static function set(string $bee, string $key, mixed $value):bool{
        //Only returns false on bee not existing.
        if(!self::remove($bee, $key)){
            return false;
        }

        self::checkAncestor($bee, $key, true);
        self::setCombo($bee, $key, $value);
        return true;
    }
    public static function remove(string $bee, string $key):bool{
        if(!isset(self::$bees[$bee])){
            return false;
        }

        unset(self::$cache[$bee][$key]);
        self::$updates[$bee][$key] = true;

        $prefix = $key . self::$bees[$bee]['seperator'];
        foreach(self::$cache[$bee] as $existingKey => $_){
            if(str_starts_with($existingKey, $prefix)){
                unset(self::$cache[$bee][$existingKey]);
                self::$updates[$bee][$existingKey] = true;
            }
        }

        return true;
    }
    private static function setCombo(string $bee, string $key, mixed $value):void{
        if(is_array($value) && !array_is_list($value)){
            foreach($value as $subKey => $subValue){
                self::setCombo($bee, $key . self::$bees[$bee]['seperator'] . $subKey, $subValue);
            }
            return;
        }

        self::$cache[$bee][$key] = $value;
        self::$updates[$bee][$key] = true;
    }
    private static function getCombo(string $bee, string $key):mixed{
        if(!isset(self::$bees[$bee])){
            mklog(2, "Could not read from bee $bee");
            return null;
        }

        $prefix = $key . self::$bees[$bee]['seperator'];
        $result = [];
        $found = false;

        foreach(self::$cache[$bee] as $fullKey => $value){
            if(!str_starts_with($fullKey, $prefix)){
                continue;
            }
            $found = true;
            $relative = substr($fullKey, strlen($prefix));
            $parts = explode(self::$bees[$bee]['seperator'], $relative);

            $ref = &$result;
            foreach($parts as $i => $part){
                if($i === count($parts) - 1){
                    $ref[$part] = $value;
                }
                else{
                    $ref[$part] ??= [];
                    $ref = &$ref[$part];
                }
            }
            unset($ref);
        }

        return $found ? $result : null;
    }
    //Normally fast stuff
    public static function increment(string $bee, string $key, int|float $by=1):int|float|null{
        if(!isset(self::$bees[$bee]) || !array_key_exists($key, self::$cache[$bee]) || !is_numeric(self::$cache[$bee][$key])){
            return null;
        }

        self::$cache[$bee][$key] += $by;
        self::$updates[$bee][$key] = true;

        return self::$cache[$bee][$key];
    }
    public static function update(string $bee, string $key, mixed $value):bool{
        if(!isset(self::$bees[$bee]) || !array_key_exists($key, self::$cache[$bee]) || (is_array($value) && !array_is_list($value))){
            return false;
        }

        self::$cache[$bee][$key] = $value;
        self::$updates[$bee][$key] = true;

        return true;
    }
    public static function inArray(string $bee, string $key, mixed $value, bool $strict=false):bool{
        if(!isset(self::$bees[$bee]) || !array_key_exists($key, self::$cache[$bee]) || !is_array(self::$cache[$bee][$key])){
            return false;
        }

        return in_array($value, self::$cache[$bee][$key], $strict);
    }
    public static function count(string $bee, string $key):?int{
        if(!isset(self::$bees[$bee]) || !array_key_exists($key, self::$cache[$bee]) || !is_array(self::$cache[$bee][$key])){
            return null;
        }

        return count(self::$cache[$bee][$key]);
    }
    public static function removeFromList(string $bee, string $key, mixed $value, bool $strict=false, bool $checkDuplicates=false):bool{
        if(!isset(self::$bees[$bee]) || !array_key_exists($key, self::$cache[$bee]) || !is_array(self::$cache[$bee][$key])){
            return false;
        }

        $valueType = gettype($value);

        foreach(self::$cache[$bee][$key] as $listValueKey => $listValue){
            if($strict){
                if($valueType !== gettype($listValue)){
                    continue;
                }
            }

            if($value != $listValue){
                continue;
            }

            unset(self::$cache[$bee][$key][$listValueKey]);

            if(!$checkDuplicates){
                break;
            }
        }

        self::$cache[$bee][$key] = array_values(self::$cache[$bee][$key]);
        self::$updates[$bee][$key] = true;

        return true;
    }
    public static function addToList(string $bee, string $key, mixed $value, bool $addToTop=false):bool{
        if(!isset(self::$bees[$bee]) || !array_key_exists($key, self::$cache[$bee]) || !is_array(self::$cache[$bee][$key])){
            return false;
        }

        if($addToTop){
            array_unshift(self::$cache[$bee][$key], $value);
        }
        else{
            self::$cache[$bee][$key][] = $value;
        }
        
        self::$updates[$bee][$key] = true;

        return true;
    }
    public static function doThings(string $bee, array $actions):?array{
        if(!isset(self::$bees[$bee])){
            return null;
        }

        $returns = [];
        $processedActions = [];
        $actionNumber = 0;
        foreach($actions as $actionArgs){
            //Action name
            if(!is_string($actionArgs[0] ?? null)){
                return null;
            }
            $actionName = array_shift($actionArgs);

            //Action defenition
            if(!isset(self::$definitions[$actionName])){
                return null;
            }
            $actionDef = self::$definitions[$actionName];
            
            //Add bee to args
            array_unshift($actionArgs, $bee);

            //Check action args
            foreach($actionDef['args'] as $actionArgNum => $actionArgType){
                if($actionArgNum === 0){//on bee
                    continue;
                }
                if(array_key_exists($actionArgNum, $actionArgs)){
                    if($actionArgType !== "mixed"){
                        if(!in_array(gettype($actionArgs[$actionArgNum]), explode("|", $actionArgType))){
                            return null;
                        }
                    }
                }
                else{
                    if(!array_key_exists($actionArgNum, $actionDef['defaults'])){
                        return null;
                    }
                    $actionArgs[$actionArgNum] = $actionDef['defaults'][$actionArgNum];
                }
            }

            $processedActions[$actionNumber]['name'] = $actionName;
            $processedActions[$actionNumber]['args'] = $actionArgs;

            $actionNumber++;
        }

        foreach($processedActions as $actionNumber => $action){
            $actionName = $action['name'];
            $returns[$actionNumber] = self::{$actionName}(...$action['args']);
        }

        return $returns;
    }
    private static function checkAncestor(string $bee, string $key, bool $deleteOnFind=false):bool{
        $parts = explode(self::$bees[$bee]['seperator'], $key);
        array_pop($parts); // don't check the key itself, only ancestors
        $path = '';
        foreach($parts as $part){
            $path = $path === '' ? $part : $path . self::$bees[$bee]['seperator'] . $part;
            if(array_key_exists($path, self::$cache[$bee])){
                if($deleteOnFind){
                    unset(self::$cache[$bee][$path]);
                    self::$updates[$bee][$path] = true;
                }
                else{
                    return true;
                }
            }
        }
        return $deleteOnFind;
    }
    public static function isset(string $bee, string $key, bool $checkCombo=false):bool{
        if(!isset(self::$bees[$bee])){
            return false;
        }
        if(array_key_exists($key, self::$cache[$bee])){
            return true;
        }
        if($checkCombo){
            $key .= self::$bees[$bee]['seperator'];
            foreach(self::$cache[$bee] as $fullKey => $_){
                if(str_starts_with($fullKey, $key)){
                    return true;
                }
            }
        }
        return false;
    }
    public static function get(string $bee, string $key, bool $checkCombo=false):mixed{
        if(!isset(self::$bees[$bee])){
            return null;
        }

        if(array_key_exists($key, self::$cache[$bee])){
            return self::$cache[$bee][$key];
        }

        if($checkCombo){
            return self::getCombo($bee, $key);
        }

        return null;
    }
    //Saving stuff
    public static function flush():bool{
        if(count(self::$bees) < 1){
            return true;
        }

        $return = true;

        foreach(self::$bees as $bee => $_){
            if(count(self::$updates[$bee]) < 1){
                continue;
            }

            $db = &self::$bees[$bee]['con'];

            $upsert = $db->prepare(
                'INSERT INTO kv (key, value) VALUES (:k, :v)
                ON CONFLICT(key) DO UPDATE SET value = :v'
            );
            $delete = $db->prepare('DELETE FROM kv WHERE key = :k');

            $db->exec('BEGIN');
            foreach(self::$updates[$bee] as $key => $__){
                if(array_key_exists($key, self::$cache[$bee])){
                    $upsert->bindValue(':k', $key, SQLITE3_TEXT);
                    $upsert->bindValue(':v', json_encode(self::$cache[$bee][$key]), SQLITE3_TEXT);
                    $upsert->execute();
                    $upsert->reset();
                }
                else{
                    $delete->bindValue(':k', $key, SQLITE3_TEXT);
                    $delete->execute();
                    $delete->reset();
                }
            }
            
            if(!$db->exec('COMMIT')){
                mklog(3, "Failed to save changes to bee $bee");
                $return = false;
                continue;
            }
            else{
                self::$updates[$bee] = [];
            }
        }

        return $return;
    }
    public static function close():bool{
        if(count(self::$bees) < 1){
            return true;
        }

        if(!self::flush()){
            return false;
        }

        $return = true;
        foreach(self::$bees as $bee => $beeInfo){
            if(!$beeInfo['con']->close()){
                mklog(2, "Failed to safely close bee $bee");
                $return = false;
                continue;
            }

            unset(self::$bees[$bee]);
            unset(self::$cache[$bee]);
            unset(self::$updates[$bee]);
            mklog(1, "Closed bee $bee");
        }

        return $return;
    }
    //Communicator stuff
    public static function communicate(...$args):mixed{
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        if(!isset($backtrace[2]['class']) || $backtrace[2]['class'] !== "communicator_server"){
            mklog(1, 'You cannot call communicate outside of communicator_server');
            return null;
        }

        //arg 0 is action
        //arg 1 is bee
        //arg 2+ is extra args

        if(!is_string($args[1] ?? null) || !isset(self::$bees[$args[1]])){
            return null;
        }

        $bee = $args[1];
        unset($args[1]);
        $args = array_values($args);

        if(count(self::$bees[$bee]['whitelist']) > 0){
            $name = communicator::getLastReceivedName();

            if(!in_array(strtolower($name), self::$bees[$bee]['whitelist'])){
                return null;
            }
        }

        $returns = self::doThings($bee, [$args]);
        if(!is_array($returns)){
            return null;
        }
        return $returns[0];
    }
    public static function communicatorServerActions():array{
        $actions = [];
        foreach(self::$definitions as $defName => $defOps){
            $action = [
                "function" => "deebee::communicate",
                "args" => [$defName],
                "defArgs" => $defOps["defaults"]
            ];

            $numArgs = count($defOps['args']);
            for($i=0; $i<$numArgs; $i++){
                $action["args"][] = "--$i";
            }

            $actions[$defName] = $action;
        }
        return $actions;
    }
    public static function communicatorServerThingsToDo():array{
        return [
            [
                "type" => "startup",
                "function" => 'deebee::start()'
            ],
            [
                "type" => "repeat",
                "interval" => 10,
                "function" => 'deebee::flush()'
            ],
            [
                "type" => "shutdown",
                "function" => 'deebee::close()'
            ],
        ];
    }
}