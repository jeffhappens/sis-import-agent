<?php
    // Connect to the Canvas API and initiate an SIS import
    // One argument is required: The name of the file to send
    // Usage: php import2.php <filename>.csv
    // Make sure a filename has been provided and that it dies exist:
    if(!isset($argv[1]) || !file_exists($argv[1])) {
        die('Please supply a valid file name to send'."\n");
    }
    // Continue if we get a valid file
    // Values that script will need to connect to the API
    $apiurl = '<domain>/api/v1/';
    $apitoken = '<api token>';
    $account_id = '<account id>';
    $file = array('attachment' => '@'.$argv[1]);
    $filepath = '<path to script>'; // Temporary app location
    $logpath = $filepath.'Log/';   

    // Getting ready to send the data to the Canvas API
    // Prepare cURL for the transfer
    $ch = curl_init();

    curl_setopt_array($ch, array(
        // Information the API needs to accept the transfer
        CURLOPT_URL => $apiurl.'accounts/'.$account_id.'/sis_imports.json?import_type=instructure_csv&extension=csv&access_token='.$apitoken,
        CURLOPT_RETURNTRANSFER => 1, // Tells PHP to return the response rather than printing it
        CURLOPT_POST => 1, // This is a POST request
        CURLOPT_POSTFIELDS => $file // The attachment we are sending
    ));

    $result = curl_exec($ch); // Start the transfer
    $connection_status = curl_getinfo($ch);
    if($connection_status['http_code'] !== 200) {
        die('Could not connect to the Canvas API. Exiting'."\n");
    }
    curl_close($ch); // Collect the garbage

    // Once the import is started the API will return a JSON object with details about the job
    // Here is an example response:
    /*
    stdClass Object
    (
        [id] => 4922941
        [updated_at] => 2012-10-05T09:20:16-04:00
        [data] => stdClass Object
        (
            [import_type] => instructure_csv
        )
        [workflow_state] => created
        [ended_at] => 
        [progress] => 0
        [created_at] => 2012-10-05T09:20:16-04:00
    )
    */
    
    // Capture the JSON string
    $json = json_decode($result);
    // We are interested in the value of [id]
    $id = $json->id;
    // We can query the API again using the id of the import to get progress updates
    // Query the API to get progress updates
    // Can use a GET request this time

    // Now that we have the ID of the job that we just started, we will use that to poll the API again for status updates on the job
    // When we poll the API again we get another JSON object. This time it contains information about the jobs status.
    // Here is an example:
    /* 
        stdClass Object (
            [ended_at] => 2012-10-04T11:28:29-04:00
            [created_at] => 2012-10-04T11:28:17-04:00
            [id] => 4922242
            [data] => stdClass Object (
                [counts] => stdClass Object (
                    [grade_publishing_results] => 0
                    [users] => 149
                    [sections] => 0
                    [group_memberships] => 0
                    [accounts] => 0
                    [terms] => 0
                    [courses] => 0
                    [groups] => 0
                    [enrollments] => 0
                    [xlists] => 0
                    [abstract_courses] => 0
                )
                [supplied_batches] => Array (
                    [0] => user
                )
                [import_type] => instructure_csv
            )
            [progress] => 100
            [workflow_state] => imported
            [updated_at] => 2012-10-04T11:28:29-04:00
        )       
    */
    // We will need to poll the API to get up to date information about the status of the current job
    // Sooo.... Fire up an infinite loop and check the value of [progress] until it reaches 100. Also check [workflow_state] until it returns "imported"
    // Once [progress] has reached 100, the job is complete
    // Now we can poll the API until [progress]=100 and [workflow_state]=imported
    // Fire up an infinite loop
    while(1) {
        sleep(1); // Do not abuse the API. Run the loop once per second
        $status = json_decode(file_get_contents($apiurl.'accounts/'.$account_id.'/sis_imports/'.$id.'?access_token='.$apitoken));
        // Once we reach status->100 and workflow_state->imported we break out of the loop.
        if($status->progress === 100 && $status->workflow_state === 'imported') {
            // SIS import has completed
            // Write some log files to the disk
            $logfile = fopen($logpath.$status->data->supplied_batches[0].'-'.@date('m-d-Y h:i:s',time()), 'w');
            fwrite($logfile, print_r($status, true)); // Just write the json as is to the file. It is easy enough to read and too much of a pain to parse out.
            fclose($logfile);
            // move the file out of the DataFileDrop dir and into the Archive
            $fileToMove = basename($argv[1]);
            // datestamp the file so that we can refer back to it later
            if(copy($argv[1], 'E:/inetpub/Canvas/Uploads/Imports/Archive/'.@date('m-d-Y-h-i-s').'-'.$fileToMove)) {
                unlink($argv[1]);
            }
            break;
        }
        if($job_status->progress === 100 && $job_status->workflow_state === 'imported_with_messages') {
            // The import completed but there were some errors
            // Do not move the csv file into Archive in case someone wants to fix it and run it again
            // Send out an email with the response
            $mail_subject = 'The SIS Import {'.strtoupper($job_status->data->supplied_batches[0]).'} completed but encountered errors';
            $mail_body = 'See below for details'."\n".print_r($job_status, true);
            @mail($mail_to,$mail_subject,$mail_body,$mail_headers);
            break;
        }
    }
?>