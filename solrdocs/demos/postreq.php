<?php

require_once('httputils.php');


// PostRequest
// Post data to somewhere 
class PostRequest {
    protected $urlEndpoint;
    protected $ch; // curl handler
 
    function __construct($urlEndpoint) {
        $this->urlEndpoint = $urlEndpoint;
        $this->ch = curl_init(); # To reuse the HTTP connection
    }
    
    function fullUrl($queryParams) {
        return httputils\appendQueryString($this->urlEndpoint, $queryParams); 
    }

    function handleResponse($response) {
        if ($response === FALSE) {
            echo "POST FAILED!!";
            trigger_error(curl_error($this->ch));
        }
        return $response;
    }

    function postData($queryParams, $data, $contentType) {
        $contentType = array($contentType);
        $url = $this->fullUrl($queryParams);
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $contentType); 
        $response = $this->handleResponse(curl_exec($this->ch));
        return $response;
    }
}


// Post a series of files directly
class PostFilesRequest extends PostRequest {

    var $batchSize = 1000;
    var $count;
    var $mh;
    var $currBatch;

    function __construct($urlEndpoint) {
        parent::__construct($urlEndpoint);
        $this->count = 0;
        $this->mh = null;
        $this->resetMultiHandle();
        $this->currBatch = array();
    }

    function resetMultiHandle() {
        if ($this->mh) {
            curl_multi_close($this->mh);
        }
        $this->mh = curl_multi_init(); 
        $this->currBatch = array();
    }

    function runBatch() {
        $active = False;
        do {
            $mrc = curl_multi_exec($this->mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);


        while ($active && $mrc == CURLM_OK) {

            if (curl_multi_select($this->mh, $active)) {
                do {
                    $mrc = curl_multi_exec($this->mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }
    }

    function finishBatch() {
        $contents = array();
        foreach ($this->currBatch as $ch) {
            $contents[] = curl_multi_getcontent($ch);
            curl_close($ch);
        }
        return $contents;
    }

    function batchOrExec($ch) {
        $this->count++;
        $this->currBatch[] = $ch;
        curl_multi_add_handle($this->mh, $ch);
        if (($this->count % $this->batchSize) == 0) {
            $this->runBatch();
            $allArr = $this->finishBatch();
            $this->resetMultiHandle();
            return $allArr;
        }
        return FALSE;
    }
    

    // Takes a glob pattern and posts 
    // those files up
    function executeGlob($queryParams, $globPattern, $contentType) {
        $files = array();
        $numFiles = 0;
        $url = $this->fullUrl($queryParams);
        $contentType = array($contentType);
        foreach (glob($globPattern, GLOB_NOSORT) as $filename) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $contentType); 
            curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filename));
            $results = $this->batchOrExec($ch);
            if ($results) {
                foreach ($results AS $result) {
                    $response = $this->handleResponse($result);
                }
            }
            echo "Posted $numFiles -- $filename           \r";
            ++$numFiles;
        }
    }


    // Takes get parameters + an array of file paths 
    // to post. May also specify a single file
    function execute($queryParams, $files, $contentType) {
        // Modified from: 
        // http://stackoverflow.com/a/3892820/8123

        $data="";
        if (!is_array($files)) {
            //Convert to array
            $files=array($files);
        }
        $n=sizeof($files);
        for ($i=0;$i<$n;$i++) {
            $data .= file_get_contents($files[$i]); 
        }

        return $this->postData($queryParams, $data, $contentType);
    }
}

// Test only if run directly from CLI
if (basename($argv[0]) == basename(__FILE__)) {
    $queryParams = array('tr' => 'stateDecodedXml.xsl');
    $lawSearcher = new PostFilesRequest("http://localhost:8983/solr/statedecoded/update/xslt");
    $file = "lawsamples/31-45.xml";
    print $lawSearcher->execute($queryParams, $file, "Content-Type: application/xml; charset=US-ASCII");
}

?>
