import apiConfig from '../../../config/apiConfig';

const endpoint = path => `super-admin/${path}`;

const superAdminApi = {
    get: (path, params) => apiConfig.get(endpoint(path), { params }).then(response => response.data.data),
    post: (path, body) => apiConfig.post(endpoint(path), body).then(response => response.data),
    patch: (path, body) => apiConfig.patch(endpoint(path), body).then(response => response.data),
    put: (path, body) => apiConfig.put(endpoint(path), body).then(response => response.data),
    delete: (path, body) => apiConfig.delete(endpoint(path), { data: body }).then(response => response.data),
};

export default superAdminApi;
