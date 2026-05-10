# Third-party notices

Trondealer Payments for WooCommerce bundles assets and code from the following third-party projects. Their respective licenses are reproduced below.

---

## web3icons

The SVG icons under `assets/images/networks/` and `assets/images/coins/` are taken from the [web3icons](https://github.com/0xa3k5/web3icons) project (commit `main` at the time of bundling) and are distributed under the **MIT License**.

```
MIT License

Copyright (c) 2024 0xa3k5

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

### A note on trademarks

The web3icons SVGs depict third-party brand marks (USDT, USDC, Ethereum, Polygon, Solana, Base, Arbitrum, Optimism, Avalanche, BNB Smart Chain, TRON). Those marks remain the property of their respective owners and are used here solely for nominative/descriptive purposes — to identify the chain or asset a payment can be made on. No endorsement by, or affiliation with, those projects is implied. Remove or replace any icon if you do not have permission to use the underlying mark in your jurisdiction.

---

## QR codes (runtime, not bundled)

The thank-you page generates QR codes by linking to `https://api.qrserver.com/v1/create-qr-code/`, a free public service. No image data is bundled with the plugin. You can replace the QR endpoint via the `tdp_qr_endpoint` filter (planned) or by editing `templates/checkout-payment.php`.
