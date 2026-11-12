<?php

// Only for a host that won't let the API's address point at shopco-api/public (DirectAdmin often
// doesn't): this folder goes where the address does point, and the API itself stays safely outside
// the web folder. Put your real home folder in the line below; check-host.php or File Manager shows it.
require '/home/ACCOUNT/shopco-api/public/index.php';
