import apiConfig from '../../../config/apiConfig';

const resource = 'super-admin/landing-page';

export const getLandingPage = () => apiConfig.get(resource).then(response => response.data.data);

export const updateLandingPage = payload => apiConfig.put(resource, payload).then(response => response.data.data);

export const uploadLandingImage = (file, section) => {
    const body = new FormData();
    body.append('image', file);
    body.append('section', section);
    return apiConfig.post(`${resource}/upload`, body).then(response => response.data.data.url);
};
