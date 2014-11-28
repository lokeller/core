<?php
/**
 * Copyright (c) 2014 Lorenzo Keller <lorenzo.keller@gmail.com>
 * This file is public domain.

 * See the COPYING-README file.
 */

namespace OC\Files\Stream;

class SeekableHttp {

    public $context;

    // keeps track of the number of bytes written to the temporary file
    var $read_bytes = 0;

    // context used to download the file
    var $curl;
    var $multi_curl;

    // a file resource pointing to a temporary file where we store the download 
    var $temp_file;

    // different than null as long as we are downloading from the remote server
    var $active;

    // position at which our client (the person that opened the stream with our)
    // protocol wants the next read to happen 
    var $position = 0;

    function write_to_temp($res, $data) {

        // write the newly received data at the end of the temporary file
        fseek($this->temp_file, $this->read_bytes);
        $written = fwrite($this->temp_file, $data);
        $this->read_bytes += $written;
        return $written;

    }

    public function stream_open($path, $mode, $options, &$opened_path) {

        // open the temporary file that will store the partial download
        $this->temp_file = fopen('php://temp', 'rwb');

        // setup the download
        $this->curl = curl_init();

        $args = stream_context_get_options($this->context)['seekablehttp'];

        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->curl, CURLOPT_PROTOCOLS,  CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($this->curl, CURLOPT_REDIR_PROTOCOLS,  CURLPROTO_HTTP | CURLPROTO_HTTPS);

        curl_setopt($this->curl, CURLOPT_HEADER, 0);
        curl_setopt($this->curl, CURLOPT_URL, $args['url']);
        curl_setopt($this->curl, CURLOPT_USERPWD, $args['userpwd']);


        if ( array_key_exists('secure', $args) && $args['secure'] ) {

            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

            if ( array_key_exists('capath', $args) ) {
                curl_setopt($curl, CURLOPT_CAINFO, $args['capath']);
            }

        }

        // set a callback that will be called when new data comes in
        curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, 
                        array($this, 'write_to_temp'));

        $this->multi_curl = curl_multi_init();

        curl_multi_add_handle($this->multi_curl,$this->curl);

        $this->active = null;

        // start the download
        do {
            $mrc = curl_multi_exec($this->multi_curl, $this->active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        if ( $mrc != CURLM_OK ) {
            $this->stream_close();
        }

        return true;
    }

    public function stream_close() {
        // clean up when closing the download
        $active = null;

        $statusCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        if ($statusCode !== 200) {

            $effective_url = curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL);
            \OCP\Util::writeLog('webdav client', 
                'curl GET ' . $effective_url .
                ' returned status code ' . $statusCode, \OCP\Util::ERROR);

        }

        curl_multi_remove_handle($this->multi_curl, $this->curl);
        curl_multi_close($this->multi_curl);

    }


    public function stream_read($count) {

        if ( $this->stream_eof()) {
            return FALSE;
        }

	$this->wait_for_position($this->position + $count)

        fseek($this->temp_file, $this->position);

        $data = fread($this->temp_file, $available_count);

        $this->position += strlen($data);

        return $data;

    }

    public function stream_tell() {
        return $this->position;
    }

    function wait_data() {
        // wait for incoming data
        if (curl_multi_select($this->multi_curl) != -1) {

            // read the data
            do {
                $mrc = curl_multi_exec($this->multi_curl, $this->active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);


            if ( $mrc != CURLM_OK) {
                $this->stream_close();
            }

        } else {
            $this->stream_close();
        }
    }

    function wait_for_end() {
        // wait until the download is over
        while ( $this->active) {
            $this->wait_data();
        }
    }

    function wait_for_position($position) {
        // wait until we read past the position or the download is over
        while ( $position >= $this->read_bytes && $this->active) {
            $this->wait_data();
        }
    }

    public function stream_eof() {

        // here we could rely on the Content-Lenght field of the header
        $this->wait_for_position($this->position);

        // return FALSE if the request is past the end of the file
        return $this->position >= $this->read_bytes ;
    }

    public function stream_set_option($option, $arg1, $arg2) {
        return false;
    }

    public function url_stat($path) {
        return false;
    }

    public function stream_stat() {
        return false;
    }

    public function stream_seek($offset, $whence) {

        switch ($whence) {
            case SEEK_SET:

                $this->wait_for_position($offset);

                if ($this->read_bytes > $offset) {
                    $this->position = $offset;
                    return TRUE;
                } else {
                    return FALSE;
                }

                break;

            case SEEK_CUR:

                return $this->stream_seek($this->position + $offset, SEEK_SET);

            case SEEK_END:

                // here we could rely on the Content-Lenght field of the header
                $this->wait_for_end();
                return $this->stream_seek($this->position + $offset, SEEK_SET);

            default:
                return false;
        }
    }

}
