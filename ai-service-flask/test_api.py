import requests
import json

response = requests.post('http://localhost:5000/api/recommend/hybrid',
                        json={'query_text':'kem dưỡng cho da dầu','user_profile':{'skin_type':'da dầu','budget':500000}})
print('Status:', response.status_code)
print('Response:', response.json())