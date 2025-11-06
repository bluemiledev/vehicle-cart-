const { createProxyMiddleware } = require('http-proxy-middleware');

module.exports = function(app) {
  // Proxy for smartdatalink.com.au APIs
  app.use(
    '/reet_python',
    createProxyMiddleware({
      target: 'https://smartdatalink.com.au',
      changeOrigin: true,
      secure: true,
      logLevel: 'debug',
      // No pathRewrite needed - keep the path as is
    })
  );

  // Proxy for no-reply.com.au APIs (existing)
  app.use(
    '/smart_data_link',
    createProxyMiddleware({
      target: 'https://no-reply.com.au',
      changeOrigin: true,
      secure: true,
      logLevel: 'debug',
    })
  );
};

