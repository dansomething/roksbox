<?php
//----------------------------------------------------------------------
// Forked from original source at
//     http://roksbox.com/downloads/download.php?download_file=videoxml.php
//
// ChangeLog
//
// 2013-03-31
//  * Added file caching to improve performance on large libraries
//----------------------------------------------------------------------

$tree=array();
$cache_file = "video_cache.xml";
$cache_ttl = 3600;

function getDirectory($path = '.'){

    $ignore = array( 'cgi-bin', '.', '..' );
    // Directories to ignore when listing output. Many hosts
    // will deny PHP access to the cgi-bin.

    $dh = @opendir( $path );
    // Open the directory to the handle $dh

    //Make counter
    $j=0;
    $temp=array();

    while( false !== ( $file = readdir( $dh ) ) ){
        // Loop through the directory

        if( !in_array( $file, $ignore ) ){
            // Check that this file is not to be ignored

            // Feed with file name
            $temp[$j]['name']=$path . "/" . $file;

            if( is_dir( "$path/$file" ) ){
                // Its a directory, so we need to keep reading down...
                $temp[$j]['children']=getDirectory( "$path/$file");
            }

        }

        $j++; //counting

    }
    return $temp;

    closedir( $dh );
    // Close the directory handle

}//end of function

//recursive function for sorting arrays
function getSort(&$temp) {
    global $type;

    switch ($type) {
    case 'desc':
        rsort($temp);
        break;
    case 'asc':
        sort($temp);
        break;
    }

    foreach($temp as &$t) {
        if (array_key_exists('children', $t)) {
            if (is_array($t['children'])) {
                getSort($t['children']);
            }
        }
    }
} //end of function

function getInfo($filename, $xmltype) {
    if (!is_readable($filename)) {
        return null;
    }

    $info = array();

    $data = implode("", file($filename));
    $parser = xml_parser_create();
    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
    xml_parse_into_struct($parser, $data, $values, $tags);
    xml_parser_free($parser);

    if (array_key_exists('viddb', $tags)) {
        return null;
    }

    if (array_key_exists('episodename', $tags)) {
        $info['title'] = $values[$tags['episodename'][0]]['value'];
    }
    else if (array_key_exists('title', $tags)) {
        $info['title'] = $values[$tags['title'][0]]['value'];
    }
    else {
        $info['title'] = null;
    }

    if (array_key_exists('genre', $tags)) {
        if ($xmltype == "nfo") {
            $genres = null;
            for ($i=$tags['genre'][0]+1; $i<$tags['genre'][1]; $i++) {
                $genres = $genres . ", " . $values[$i]['value'];
            }
            $info['genre'] = substr($genres, 2);
        }
        else {
            $info['genre'] = $values[$tags['genre'][0]]['value'];
        }
    }
    else {
        $info['genre'] = null;
    }

    return $info;

}

function parse_tree($tree, $cache_file) {
    foreach ($tree as $path) {
        if (is_array($path)) {
            parse_tree($path, $cache_file);
        }
        else {
            if( is_file( $path ) ){
                if ( preg_match("/.(\.mp4|\.m4v|\.mkv|\.mov|\.wmv|\.ts|\.m3u8|\.pxml)$/i", $path) ) {
                    $path = str_replace("&", "&amp;", $path);
                    $dirpath = substr($path, 2);
                    $path_parts = pathinfo($dirpath);

                    $xmlfile = str_replace($path_parts['extension'], "nfo", $dirpath);
                    if (is_readable($xmlfile)) {
                        $info = getInfo($xmlfile, "nfo");
                        $info['xmlfile'] = $xmlfile . "|nfo";
                    }
                    else {
                        $xmlfile = str_replace($path_parts['extension'], "xml", $dirpath);
                        if (is_readable($xmlfile)) {
                            $info = getInfo($xmlfile, "xml");
                            $info['xmlfile'] = $xmlfile . "|xml";
                        }
                        else {
                            $info['title'] = null;
                            $info['genre'] = null;
                            $info['xmlfile'] = null;
                        }
                    }

                    $poster = str_replace($path_parts['extension'], "jpg", $dirpath);
                    if (!is_readable($poster)) {
                        $poster = null;
                    }
                    $info['poster'] = $poster;

                    $info = str_replace("&", "&amp;", $info);
                    $title = $info['title'];
                    $genres = $info['genre'];
                    $poster = $info['poster'];
                    $xmlfile = $info['xmlfile'];

                    if ($title == null) {
                        $title = $path_parts['filename'];
                    }

                    if ($genres == null) {
                        $genres = "[" . $path_parts['dirname'] . "]";
                    }

                    $movie_xml = <<<END

  <movie>
    <origtitle>$title</origtitle>
    <genre>$genres</genre>
    <path>$path</path>
    <poster>$poster</poster>
    <xmlhref>$xmlfile</xmlhref>
  </movie>
END;
                    write_cache($cache_file, $movie_xml);
                }
            }
        }
    }
}

function check_cache($cache_file, $cache_ttl) {
    $fmtime = 0;

    if (file_exists($cache_file)) {
        $fmtime = filemtime($cache_file);
        if (time() - $fmtime > $cache_ttl) {
            unlink($cache_file);
            $fmtime = 0;
        }
    }

    return $fmtime;
}

function read_cache($cache_file) {
    return file_get_contents($cache_file);
}

function write_cache($cache_file, $contents) {
    file_put_contents($cache_file, $contents, FILE_APPEND);
}

$file_modified_time = check_cache($cache_file, $cache_ttl);
if (!$file_modified_time) {
    //Put the file directory system in an array first...
    $tree=getDirectory(".");

    $type='asc'; //set sorting type 'desc' or 'asc'

    //Go through arrays again for sorting...now we have new sorted array $tree...
    getSort($tree);

    $open_xml = <<<END
<xml>
<viddb>
END;
    write_cache($cache_file, $open_xml);

    parse_tree($tree, $cache_file);

    $end_xml = <<<END

</viddb>
</xml>
END;
    write_cache($cache_file, $end_xml);
}

header("Content-type: text/xml");
header("Pragma: cache");
header("Cache-Control: max-age=$cache_ttl");
header("Expires: " . gmdate('D, d M Y H:i:s \G\M\T', $file_modified_time + $cache_ttl));
echo read_cache($cache_file);

