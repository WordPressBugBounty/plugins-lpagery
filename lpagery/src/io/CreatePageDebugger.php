<?php

namespace LPagery\io;

class CreatePageDebugger
{
    /**
     * @var CreatePageDebugger|null Singleton instance
     */
    private static $instance = null;
    
    /**
     * Hook profiling data
     * @var array
     */
    private $hook_profiler = array(
        'enabled' => false,
        'hooks' => array(),
        'stack' => array(),
    );
    
    /**
     * Get singleton instance
     * 
     * @return CreatePageDebugger
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Collects database queries executed during the request and returns them as an array.
     * 
     * @param int $initial_query_count The number of queries that existed before this request
     * @return array The collected queries with timing information (always returns a structured object)
     */
    public function collect_database_queries($initial_query_count = 0)
    {
        global $wpdb;
        
        // Default empty result structure that matches the frontend DebugQueriesSchema
        $empty_result = array(
            "timestamp" => date('Y-m-d H:i:s'),
            "total_queries" => 0,
            "total_time_ms" => 0,
            "queries" => array()
        );
        
        if (!defined('SAVEQUERIES') || !SAVEQUERIES) {
            return $empty_result;
        }
        
        if (!is_array($wpdb->queries)) {
            return $empty_result;
        }
        
        // Get only queries from this request
        $queries = array_slice($wpdb->queries, $initial_query_count);
        
        if (empty($queries)) {
            return $empty_result;
        }
        
        // Calculate total time
        $total_time = 0;
        foreach ($queries as $query) {
            if (isset($query[1])) {
                $total_time += $query[1];
            }
        }
        
        // Build query list
        $query_list = array();
        foreach ($queries as $index => $query) {
            $query_list[] = array(
                "index" => $index + 1,
                "query" => isset($query[0]) ? $query[0] : 'Unknown query',
                "time_ms" => isset($query[1]) ? round($query[1] * 1000, 2) : 0,
            );
        }
        
        return array(
            "timestamp" => date('Y-m-d H:i:s'),
            "total_queries" => count($queries),
            "total_time_ms" => round($total_time * 1000, 2),
            "queries" => $query_list
        );
    }
    
    /**
     * Start hook profiling by attaching to the 'all' action
     */
    public function start_hook_profiler()
    {
        $this->hook_profiler['enabled'] = true;
        $this->hook_profiler['hooks'] = array();
        $this->hook_profiler['stack'] = array();
        
        add_action('all', array($this, 'hook_profiler_start'), 1);
    }
    
    /**
     * Stop hook profiling
     */
    public function stop_hook_profiler()
    {
        $this->hook_profiler['enabled'] = false;
        
        remove_action('all', array($this, 'hook_profiler_start'), 1);
    }
    
    /**
     * Hook profiler callback - tracks when each hook starts
     * 
     * @param string $hook_name The name of the hook being executed
     */
    public function hook_profiler_start($hook_name)
    {
        global $wp_filter;
        
        if (!$this->hook_profiler['enabled']) {
            return;
        }
        
        // Skip our own profiler hooks to avoid infinite recursion
        if (strpos($hook_name, 'lpagery_hook_profiler') !== false) {
            return;
        }
        
        // Skip internal WordPress hooks that fire too frequently
        $skip_hooks = array('gettext', 'gettext_with_context', 'ngettext', 'sanitize_title', 'attribute_escape', 'clean_url');
        if (in_array($hook_name, $skip_hooks)) {
            return;
        }
        
        // Get callbacks for this hook
        if (!isset($wp_filter[$hook_name])) {
            return;
        }
        
        $hook = $wp_filter[$hook_name];
        if (!($hook instanceof \WP_Hook)) {
            return;
        }
        
        $callbacks = $hook->callbacks;
        if (empty($callbacks)) {
            return;
        }
        
        // Store the start time for this hook
        $hook_id = $hook_name . '_' . microtime(true);
        $this->hook_profiler['stack'][$hook_id] = array(
            'hook' => $hook_name,
            'start_time' => microtime(true),
            'callbacks' => array(),
        );
        
        // Collect callback information
        foreach ($callbacks as $priority => $callback_group) {
            foreach ($callback_group as $callback_id => $callback_data) {
                $callback_info = $this->get_callback_info($callback_data['function']);
                if ($callback_info) {
                    $this->hook_profiler['stack'][$hook_id]['callbacks'][] = $callback_info;
                }
            }
        }
        
        // Add a callback to measure time after hook completes
        // IMPORTANT: Must accept and return the first argument to preserve filter values
        add_filter($hook_name, function($value = null) use ($hook_id) {
            return self::get_instance()->hook_profiler_end($hook_id, $value);
        }, PHP_INT_MAX, 1);
    }
    
    /**
     * Hook profiler callback - tracks when each hook ends
     * 
     * @param string $hook_id The unique identifier for this hook execution
     * @param mixed $value The filter value (must be returned)
     * @return mixed The filter value (unchanged)
     */
    public function hook_profiler_end($hook_id, $value = null)
    {
        if (!isset($this->hook_profiler['stack'][$hook_id])) {
            return $value;
        }
        
        $hook_data = $this->hook_profiler['stack'][$hook_id];
        $end_time = microtime(true);
        $duration = ($end_time - $hook_data['start_time']) * 1000; // Convert to ms
        
        // Only record hooks that took measurable time
        if ($duration > 0.01) {
            $this->hook_profiler['hooks'][] = array(
                'hook' => $hook_data['hook'],
                'time_ms' => round($duration, 2),
                'callbacks' => $hook_data['callbacks'],
            );
        }
        
        unset($this->hook_profiler['stack'][$hook_id]);
        
        return $value;
    }

    private function get_callback_info($callback)
    {
        $info = array(
            'callback' => 'Unknown',
            'source' => 'Unknown',
        );
        
        try {
            if (is_string($callback)) {
                $info['callback'] = $callback;
                if (function_exists($callback)) {
                    $ref = new \ReflectionFunction($callback);
                    $info['source'] = $this->get_relative_path($ref->getFileName());
                }
            } elseif (is_array($callback) && count($callback) === 2) {
                $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
                $method = $callback[1];
                $info['callback'] = $class . '::' . $method;
                
                if (method_exists($class, $method)) {
                    $ref = new \ReflectionMethod($class, $method);
                    $info['source'] = $this->get_relative_path($ref->getFileName());
                }
            } elseif (is_object($callback) && $callback instanceof \Closure) {
                $ref = new \ReflectionFunction($callback);
                $info['callback'] = 'Closure';
                $info['source'] = $this->get_relative_path($ref->getFileName()) . ':' . $ref->getStartLine();
            }
        } catch (\Exception $e) {
            // Silently fail for callbacks we can't reflect
        }
        
        return $info;
    }
    
    /**
     * Get a relative path from the WordPress root
     * 
     * @param string $path The absolute path
     * @return string The relative path
     */
    private function get_relative_path($path)
    {
        if (empty($path)) {
            return 'Unknown';
        }
        
        $wp_root = ABSPATH;
        $wp_content = WP_CONTENT_DIR;
        
        // Try to make path relative to wp-content first (more readable for plugins)
        if (strpos($path, $wp_content) === 0) {
            return 'wp-content' . substr($path, strlen($wp_content));
        }
        
        // Fall back to WordPress root
        if (strpos($path, $wp_root) === 0) {
            return substr($path, strlen($wp_root));
        }
        
        return basename($path);
    }
    
    /**
     * Collect slow hooks data and return top 20 slowest
     * 
     * @return array The slow hooks data (always returns a structured object)
     */
    public function collect_slow_hooks()
    {
        // Default empty result structure
        $empty_result = array(
            "total_hooks" => 0,
            "total_time_ms" => 0,
            "hooks" => array()
        );
        
        if (empty($this->hook_profiler['hooks'])) {
            return $empty_result;
        }
        
        $hooks = $this->hook_profiler['hooks'];
        
        // Sort by time descending
        usort($hooks, function($a, $b) {
            return $b['time_ms'] <=> $a['time_ms'];
        });
        
        // Get top 20 slowest
        $top_hooks = array_slice($hooks, 0, 20);
        
        // Calculate total time
        $total_time = 0;
        foreach ($hooks as $hook) {
            $total_time += $hook['time_ms'];
        }
        
        // Format the hooks list
        $hook_list = array();
        foreach ($top_hooks as $index => $hook) {
            // Get the primary callback (first one, usually the main one)
            $primary_callback = !empty($hook['callbacks']) ? $hook['callbacks'][0] : array('callback' => 'Unknown', 'source' => 'Unknown');
            
            $hook_list[] = array(
                "index" => $index + 1,
                "hook" => $hook['hook'],
                "callback" => $primary_callback['callback'],
                "source" => $primary_callback['source'],
                "time_ms" => $hook['time_ms'],
            );
        }
        
        return array(
            "total_hooks" => count($hooks),
            "total_time_ms" => round($total_time, 2),
            "hooks" => $hook_list
        );
    }
}
