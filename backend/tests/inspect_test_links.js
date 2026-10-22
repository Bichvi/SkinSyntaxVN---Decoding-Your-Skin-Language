const http = require('http');

http.get('http://localhost/index.php?r=tatca', res => {
  let data = '';
  res.on('data', chunk => data += chunk);
  res.on('end', () => {
    const matches = data.match(/href="[^"]*r=chitiet[^"]*"/g) || [];
    console.log('Sample product detail URLs:', matches.slice(0, 5));
  });
});
