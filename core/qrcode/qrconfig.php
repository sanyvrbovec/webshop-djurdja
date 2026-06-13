<?php
/*
 * PHP QR Code encoder
 *
 * Config file, feel free to modify
 */
     
    // ĐurđaShop: cache isključen (ne piše u core/ — radi na svim hostinzima bez writable prava)
    define('QR_CACHEABLE', false);
    define('QR_CACHE_DIR', false);
    define('QR_LOG_DIR', false);

    define('QR_FIND_BEST_MASK', false);                                                         // fiksni mask — brzo, bez cache; QR ostaje validan/skenabilan
    define('QR_FIND_FROM_RANDOM', false);                                                       // if false, checks all masks available, otherwise value tells count of masks need to be checked, mask id are got randomly
    define('QR_DEFAULT_MASK', 2);                                                               // when QR_FIND_BEST_MASK === false
                                                  
    define('QR_PNG_MAXIMUM_SIZE',  1024);                                                       // maximum allowed png image width (in pixels), tune to make sure GD and PHP can handle such big images
                                                  