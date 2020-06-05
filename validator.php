<?php 
require('../../../wp-load.php');
?>
<script>
      var hash = location.hash;
      var adminUrl = '<?= admin_url( 'admin-ajax.php?action=citizenos-connect-authorize' ); ?>';
      location.href = adminUrl + hash.replace('#', '&');
</script>