<?php
if(@get_headers($argv[1])[0] == 'HTTP/1.1 200 OK')
	echo @file_get_contents($argv[1]);