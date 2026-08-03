<?php $c=new mysqli('localhost','root','','saaes_db'); $r=$c->query('SHOW INDEXES FROM users'); while($row=$r->fetch_assoc()) { print_r($row); }
