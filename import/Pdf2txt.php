<?php
// Usage        : Pdf2Text::pdf2txt($filename)
// Arguments   : $filename - Filename of the PDF you want to extract
// Description : Reads a pdf file, extracts data streams, and manages
//               their translation to plain text - returning the plain
//               text at the end
// Authors      : Jonathan Beckett, 2005-05-02
//              : Sven Schuberth, 2007-03-29
//              : adrian & Alessio Dal Bianco 2013-05-14
class Pdf2Text {
    // Global table for codes replacement
    private static $TCodeReplace = array('\(' => '(', '\)' => ')');

    public static function pdf2txt($filepath) {

        $data = self::getFileData($filepath);

        $s = strpos($data, "%") + 1;

        $version = substr($data, $s, strpos($data, "%", $s) - 1);
        if (substr_count($version, "PDF-1.2") == 0)
            return self::handleV2($data);
        // This used to point to V3, but that never did anything with newer PDFs anyways
        else
            return self::handleV2($data);

    }

    // handles the verson 1.2
    private static function handleV2($data) {

        // try detecting \n, \r or \r\n variation
        $tmp2 = strpos($data, "stream");
        $end_stream_delimiter = substr($data, $tmp2 + 6, 2);

        if ($end_stream_delimiter != "\r\n") {
            $end_stream_delimiter = substr($end_stream_delimiter, 0, 1);
        }
        //echo bin2hex($end_stream_delimiter); // - debug information

        // grab objects and then grab their contents (chunks)
        $a_obj = self::getDataArray($data, "obj", "endobj");

        foreach ($a_obj as $obj) {

            $a_filter = self::getDataArray($obj, "<<", ">>");

            if (is_array($a_filter)) {
                $j++;
                $a_chunks[$j]["filter"] = $a_filter[0];

                $a_data = self::getDataArray($obj, "stream" . $end_stream_delimiter, "endstream");
                if (is_array($a_data)) {
                    $a_chunks[$j]["data"] = substr($a_data[0], strlen("stream" . $end_stream_delimiter), strlen($a_data[0]) - strlen("stream" . $end_stream_delimiter) - strlen("endstream"));
                }
            }
        }

        // decode the chunks
        foreach ($a_chunks as $chunk) {

            // look at each chunk and decide how to decode it - by looking at the contents of the filter
            $a_filter = preg_split("/", $chunk["filter"]);

            if ($chunk["data"] != "") {
                // look at the filter to find out which encoding has been used
                if (substr($chunk["filter"], "FlateDecode") !== false) {
                    $data = @ gzuncompress($chunk["data"]);
                    if (trim($data) != "") {
                        // CHANGED HERE, before: $result_data .= ps2txt($data);
                        $result_data .= self::FilterNonText(self::PS2Text_New($data));
                    } else {

                        //$result_data .= "x";
                    }
                }
            }
        }
        $result_data = preg_replace("/[^A-Za-z0-9\s\s+]/", "", addslashes($result_data));
        // I added this to remove additional junk
        $result_data = preg_replace('/([^\s]{20,})(?=[^\s])/m', '', $result_data);
        // remove long strings of text
        $result_data = preg_replace('/([^\s]{0,})([A-Za-z])([\d])([^\s]{0,})/m', '', $result_data);
        // remove strings of numbers and letters together
        $result_data = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $result_data);
        // remove unnecessary line breaks
        $result_data = preg_replace('/\s\s+/', ' ', $result_data);
        //remove extra spaces between words

        return $result_data;
    }

    private static function FilterNonText($data) {
        for ($i = 1; $i < 9; $i++) {
            if (strpos($data, chr($i)) !== false) {
                return "";
                // not text, something strange
            }
        }
        return $data;
    }

    // New function - Extract text from PS codes
    private static function ExtractPSTextElement($SourceString) {
        $Result = "";
        $CurStartPos = 0;
        while (($CurStartText = strpos($SourceString, '(', $CurStartPos)) !== FALSE) {
            // New text element found
            if ($CurStartText - $CurStartPos > 8)
                $Spacing = ' ';
            else {
                $SpacingSize = substr($SourceString, $CurStartPos, $CurStartText - $CurStartPos);
                if ($SpacingSize < -25)
                    $Spacing = ' ';
                else
                    $Spacing = '';
            }
            $CurStartText++;

            $StartSearchEnd = $CurStartText;
            while (($CurStartPos = strpos($SourceString, ')', $StartSearchEnd)) !== FALSE) {
                if (substr($SourceString, $CurStartPos - 1, 1) != '\\')
                    break;
                $StartSearchEnd = $CurStartPos + 1;
            }
            if ($CurStartPos === FALSE)
                break;
            // something wrong happened

            // Remove ending '-'
            if (substr($Result, -1, 1) == '-') {
                $Spacing = '';
                $Result = substr($Result, 0, -1);
            }

            // Add to result
            $Result .= $Spacing . substr($SourceString, $CurStartText, $CurStartPos - $CurStartText);
            $CurStartPos++;
        }
        // Add line breaks (otherwise, result is one big line...)
        return $Result . "\n";
    }

    // New function, replacing old "ps2txt" function
    private static function PS2Text_New($PS_Data) {

        // Catch up some codes
        if (ord($PS_Data[0]) < 10)
            return '';
        if (substr($PS_Data, 0, 8) == '/CIDInit')
            return '';

        // Some text inside (...) can be found outside the [...] sets, then ignored
        // => disable the processing of [...] is the easiest solution

        $Result = self::ExtractPSTextElement($PS_Data);

        // echo "Code=$PS_Data\nRES=$Result\n\n";

        // Remove/translate some codes
        return strtr($Result, self::$TCodeReplace);
    }

    //handles versions >1.2
    private static function handleV3($data) {
        // grab objects and then grab their contents (chunks)
        $a_obj = self::getDataArray($data, "obj", "endobj");
        $result_data = "";
        foreach ($a_obj as $obj) {
            //check if it a string
            if (substr_count($obj, "/GS1") > 0) {
                //the strings are between ( and )
                preg_match_all("|\((.*?)\)|", $obj, $field, PREG_SET_ORDER);
                if (is_array($field))
                    foreach ($field as $data)
                        $result_data .= $data[1];
            }
        }
        return $result_data;
    }

    private static function getFileData($filename) {
        //$handle = fopen($filename,"rb");
        //$data = fread($handle, filesize($filename));
        //fclose($handle);
        $data = file_get_contents($filename);
        return $data;
    }

    private static function getDataArray($data, $start_word, $end_word) {

        $start = 0;
        $end = 0;
        unset($a_result);

        while ($start !== false && $end !== false) {
            $start = strpos($data, $start_word, $end);
            if ($start !== false) {
                $end = strpos($data, $end_word, $start);
                if ($end !== false) {
                    // data is between start and end
                    $a_result[] = substr($data, $start, $end - $start + strlen($end_word));
                }
            }
        }
        return $a_result;
    }

}
?>
