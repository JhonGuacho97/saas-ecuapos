import React from 'react';
import {Helmet} from 'react-helmet';
import {useSelector} from "react-redux";

const TabTitle = (props) => {
    const { title } = props;
    const {frontSetting} = useSelector(state => state)
    const companyName = frontSetting?.value?.company_name || 'EcuaPos';
    const favicon = frontSetting?.value?.logo || '/favicon.ico';

    return (
        <Helmet>
            <title>{`${title} | ${companyName}`}</title>
            <link rel="icon" type="image/png" href={favicon} sizes="16x16" />
        </Helmet>
    )
}

export default TabTitle;
