<?php
/**
 * A connection to a Rexster ReST server.
 */
class Rexster
{
    public $base_url;
    private $curl;
    
    public function __construct($base_url='http://localhost:8182/graphs')
    {
        $this->base_url = $base_url;
    }
    
    /**
     * Returns a list of all configured graphs.
     *
     * @return array
     */
    public function get_graphs()
    {
        $data = $this->call('GET', '/');
        return $data['graphs'];
    }
    
    
    /**
     * Get the graph named $name
     * 
     * @param string $name
     * @return RexsterGraph
     */
    public function get_graph($name)
    {
        $graph = $this->call('GET', '/' . $name);

        if (isset($graph)) {
            return new RexsterGraph($this, $graph);
        } else {
            return null;
        }
    }
    
    /**
     * Make a request of the Rexster server
     * 
     * @param string $method e.g., GET, POST
     * @param string $path e.g., /gratefulgraph/vertices
     * @param array $params
     * @param bool $as_json if true and the method is POST, then post $params as JSON
     */
    public function call($method, $path, $params=null, $as_json=false) {
        $url = $this->base_url.$path;
        //$headers = array('Accept: application/vnd.rexster-v1+json','Content-Type: text/plain');
        $headers = array('Accept: application/json');

        if ($params) {
            if ($method == 'POST' && $as_json) {
                $headers = array('Accept: application/json');
                $params = json_encode($params);
            } else {
                if ($method == 'POST') {
                    $paramcount = count($params);
                    $params = http_build_query($params);
                }
            }
        }        
    
        $options = array(
            CURLOPT_RETURNTRANSFER  => 1,           // return web page
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_HEADER          => 0,           // don't return headers
            CURLOPT_FOLLOWLOCATION  => 1,           // follow redirects
            CURLOPT_ENCODING        => "",          // handle all encodings
            CURLOPT_AUTOREFERER     => 1,           // set referrer on redirect
            CURLOPT_CONNECTTIMEOUT  => 20,          // timeout on connect
            CURLOPT_TIMEOUT         => 35,          // timeout on response
            CURLOPT_MAXREDIRS       => 10           // stop after 10 redirects
        );
        if (isset($this->curl) || $this->curl = curl_init($url)) {
            curl_setopt_array($this->curl, $options);
        
            if ($method == 'POST') {
                curl_setopt($this->curl, CURLOPT_POST      ,1);
                curl_setopt($this->curl, CURLOPT_POSTFIELDS    ,$params);
            }
            if ($method != 'GET') {
                curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
            }
            $text = curl_exec($this->curl);
            $httpcode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
            $errno = curl_errno($this->curl);
        
            if ($httpcode >= 200 && $httpcode < 300) {
                return json_decode($text, true);
            } else {
                return NULL;
            }
        } else {
            return NULL;
        }
    }
}


class RexsterGraph
{
    public function __construct($client, $data)
    {
        $this->client = $client;
        $this->name = $data['name'];
        $this->type = $data['type'];
        if(isset($data['extensions'])) {
            $this->extensions = $data['extensions'];
        }
    }
    
    
    public function call($method, $path, $params=null, $as_json=false)
    {
        return $this->client->call($method, '/'.$this->name.$path, $params, $as_json);
    }
    
    
    /**
     * Create a new vertex
     * 
     * @param mixed $id
     * @param array $properties
     * @return RexsterVertex
     */
    public function create_vertex($id=null, $properties=null)
    {
        $parts = array('vertices');
        
        if ($id)
        {
            $parts[] = rawurlencode($id);
        }
        
        $data = $this->call('POST', '/'.implode('/', $parts), $properties);
        return new RexsterVertex($this, $data['results']);
    }
    
    
    /**
     * Get the vertex with an id of $id
     *
     * @param mixed $id
     * @return RexsterVertex
     */
    public function get_vertex($id)
    {
        $data = $this->call('GET', '/vertices/'.$id);
        return new RexsterVertex($this, $data['results']);
    }
    
    
    /**
     * Delete the vertex with an id of $id
     *
     * @param mixed $id
     */
    public function delete_vertex($id)
    {
        $this->call('DELETE', '/vertices/'.$id);
    }
    
    
    /**
     * Create a new edge.
     * 
     * @param mixed $out_id
     * @param mixed $in_id
     * @param string $label
     * @param array $properties
     * @return RexsterEdge
     */
    public function create_edge($out_id, $in_id, $label, $properties=array())
    {
        $properties['_outV'] = $out_id;
        $properties['_inV'] = $in_id;
        $properties['_label'] = $label;
        $data = $this->call('POST', '/edges', $properties);
        return new RexsterEdge($this, $data['results']);
    }
    
    
    /**
     * Get the edge with an id of $id
     *
     * @param mixed $id
     * @return RexsterEdge
     */
    public function get_edge($id)
    {
        $data = $this->call('GET', '/edges/'.$id);
        return new RexsterEdge($this, $data['results']);
    }
    
    
    /**
     * Delete the edge with an id of $id
     *
     * @param mixed $id
     */
    public function delete_edge($id)
    {
        $this->call('DELETE', '/edges/'.$id);
    }
    
    
    /**
     * Execute a gremlin script
     * 
     * This method expects the result of the script to be a list
     * of vertices and/or edges.
     * 
     * @param string $script The gremlin script
     * @return mixed
     */
    public function gremlin($script)
    {
        $data = $this->call('POST', '/tp/gremlin', array('script' => $script), true);
        
        if (isset($data['results']))
        {
            $objects = array();
            
            foreach ($data['results'] as $r)
            {
                if ($r['_type'] == 'vertex')
                {
                    $objects[] = new RexsterVertex($this, $r);
                }
                else if ($r['_type'] == 'edge')
                {
                    $objects[] = new RexsterEdge($this, $r);
                }
            }
            
            return $objects;
        }
    }
    
    
    /**
     * Get an index
     *
     * @param string $name
     * @return RexsterIndex
     */
    public function get_index($name)
    {
        $data = $this->call('GET', '/indices/'.$name);
        return new RexsterIndex($this, $data);
    }
    
    
    /**
     * Get all the indexes
     * 
     * @return array
     */
    public function get_indexes()
    {
        $raw = $this->call('GET', '/indices');
        $indexes = array();
        
        foreach ($raw['results'] as $r)
        {
            $indexes[] = new RexsterIndex($this, $r);
        }
        
        return $indexes;
    }
    
    
    /**
     * Create a new index
     * 
     * @param string $name
     * @param string $class 'vertex' or 'edge'
     * @param string $type 'automatic' or 'manual'
     * @param array $keys the list of indexed keys if the index is automatic
     */
    public function create_index($name, $class='vertex', $type='manual', $keys=null)
    {
        $params = array(
            'class' => $class,
            'type' => $type
        );
        
        if ($keys)
        {
            $params['keys'] = '['.implode(',', $keys).']';
        }
        
        $data = $this->call('POST', '/indices/'.$name, $params);
        return new RexsterIndex($this, $data['results']);
    }
}

/**
 * A base class for vertices and edges.
 * 
 * Vertices and edges share the same CRUD behavior. This class
 * contains that behavior.
 */
class RexsterObject
{
    public $graph;
    public $properties;
    public $path;
    public $id;
    
    function __construct($graph, $data)
    {
        $this->graph = $graph;
        $this->id = $data['_id'];
        $this->properties = array();
        
        foreach ($data as $k => $v)
        {
            if (substr($k, 0, 1) == '_')
            {
                continue;
            }
            
            $this->properties[$k] = $v;
        }
    }
    
    
    /**
     * Get a property
     */
    public function __get($k)
    {
        return $this->properties[$k];
    }
    
    
    /**
     * Set a property
     */
    public function __set($k, $v)
    {
        $this->properties[$k] = $v;
    }
    
    
    public function __isset($k)
    {
        return isset($this->properties[$k]);
    }
    
    
    public function __unset($k)
    {
        unset($this->properties[$k]);
    }
    
    /**
     * Save changes to properties
     */
    public function save()
    {
       return  $this->graph->call('POST', '/'.$this->path.'/'.$this->id, $this->properties);
    }
    
    /**
     * Remove the object from the graph for ever and ever.
     */
    public function delete()
    {
        return $this->graph->call('DELETE', '/'.$this->path.'/'.$this->id);
    }
}


/**
 * A vertex
 */
class RexsterVertex extends RexsterObject
{
    public $path = 'vertices';
    
    /**
     * Create a new edge
     * 
     * @param $other RexsterVertex
     * @param string $label
     * @param string $properties
     */
    public function create_edge($other, $label, $properties=array())
    {
        return $this->graph->create_edge($this->id, $other->id, $label, $properties);
    }
    
    /**
     * Get a list of edges connected to this vertex.
     * 
     * @param string $direction 'in', 'out' or 'both'
     * @param string $label
     */
    public function edges($direction='out', $label=null)
    {
        $params = null;
        
        if ($label)
        {
            $params = array('_label' => $label);
        }
        
        $raw = $this->graph->call('GET', '/vertices/'.$this->id.'/'.$direction.'E', $params);
        $edges = array();
        
        foreach ($raw['results'] as $r)
        {
            $edges[] = new RexsterEdge($this->graph, $r);
        }
        
        return $edges;
    }
}


class RexsterEdge extends RexsterObject
{
    public $path = 'edges';
    public $out_id;
    public $in_id;
    public $label;
    
    
    public function __construct($graph, $data)
    {
        parent::__construct($graph, $data);
        $this->out_id = $data['_outV'];
        $this->in_id = $data['_inV'];
        $this->label = $data['_label'];
    }
}


class RexsterIndex
{
    public function __construct($graph, $data)
    {
        $this->graph = $graph;
        $this->name = $data['name'];
        $this->type = $data['type'];
        $this->class = $data['class'];
    }
    
    public function add_vertex($vertex, $key, $value)
    {
        $this->add($vertex->id, 'vertex', $key, $value);
    }
    
    
    public function add_edge($edge, $key, $value)
    {
        $this->add($edge->id, 'edge', $key, $value);
    }
    
    
    private function add($id, $class, $key, $value)
    {
        $this->graph->call('POST', '/indices/'.$this->name, array(
            'id' => $id,
            'class' => $class,
            'key' => $key,
            'value' => $value
        ));
    }
    
    
    public function get($key, $value)
    {
        $raw = $this->graph->call('GET', '/indices/'.$this->name, array(
            'key' => $key,
            'value' => $value
        ));
        
        $objects = array();
        
        foreach ($raw['results'] as $r)
        {
            if ($r['_type'] == 'vertex')
            {
                $objects[] = new RexsterVertex($this, $r);
            }
            else if ($r['_type'] == 'edge')
            {
                $objects[] = new RexsterVertex($this, $r);
            }
        }
        
        return $objects;
    }
    
    
    public function count($key, $value)
    {
        $data = $this->graph->call('GET', '/indices/'.$this->name.'/count', array(
            'key' => $key,
            'value' => $value
        ));
        
        return $data['totalSize'];
    }
    
    
    public function keys()
    {
        $data = $this->graph->call('GET', '/indices/'.$this->name.'/keys');
        
        return $data['results'];
    }
}
