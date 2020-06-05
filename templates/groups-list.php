<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Don't allow direct access
$groups = do_action('get_user_groups');
?><div class='cos_groups_list'>
<?php
    foreach($groups as $group) {
        echo $group->name;
    }
?>

</div>