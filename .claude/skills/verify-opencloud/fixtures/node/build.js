// The build script. Opencloud gives it the build env values, so the file proves that the build saw VERIFY_BUILD.
require('node:fs').writeFileSync(`${__dirname}/build-marker`, process.env.VERIFY_BUILD || 'none');
