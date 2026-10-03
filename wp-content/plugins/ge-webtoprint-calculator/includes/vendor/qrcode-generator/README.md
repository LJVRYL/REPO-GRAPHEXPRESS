# QR Code Generator (PHP)

Source: https://github.com/kazuhikoarase/qrcode-generator/blob/95af9c2e1249337047ca478ccf98650a0a30af13/php/qrcode.php
Copyright (c) 2009 Kazuhiko Arase. MIT license, preserved in source header.
Original SHA-256: 839337a00e1ab8be1916ab20702d724aa41cdd7991dd7fc8187ffbc602998b4e

Local compatibility changes only: Graphex\QR namespace (including constants), WordPress direct-access guard, static declarations on the three stateless helpers already called statically, remove closing PHP tag. No external requests or GD calls are used by the PDF. The encoder matrix is painted as vector squares with a four-module quiet zone.
