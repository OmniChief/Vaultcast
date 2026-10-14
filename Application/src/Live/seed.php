<?php
/** New installations start without sample channels or personal media. */
function relay_seed_station() {
    return ['name'=>'Vaultcast Live','mediaBaseUrl'=>'','lookaheadHours'=>24,'channels'=>[],'media'=>[],'playlists'=>[],'cycles'=>[]];
}
