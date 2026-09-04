import React, { useEffect, useState } from 'react';
import { Image, Nav, Navbar } from 'react-bootstrap-v5';
import { connect, useDispatch, useSelector } from 'react-redux';
import { Link, useNavigate } from 'react-router-dom';
import { Tokens } from '../../constants/index';
import { logoutAction } from '../../store/action/authAction';
import { setCurrentStore } from '../../store/action/storeAction';
import ChangePassword from '../auth/change-password/ChangePassword';
import { getAvatarName, getFormattedMessage } from '../../shared/sharedMethod';
import { updateLanguage } from '../../store/action/updateLanguageAction';
import { fetchAllLanguage } from '../../store/action/languageAction';
import User from '../../assets/images/avatar.png';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faMaximize, faMinimize, faUser,
    faLock, faRightFromBracket, faAngleDown,
    faStore, faCheck, faGlobe, faClock
} from '@fortawesome/free-solid-svg-icons';
import { Dropdown } from 'react-bootstrap';
import PosRegisterModel from '../posRegister/PosRegisterModel.js';
import { headerStyles } from './styles/HeaderStyles.js';

/* ── Componente ── */
const Header = (props) => {
    const {
        logoutAction, newRoutes, updateLanguage,
        selectedLanguage,
    } = props;

    const navigate = useNavigate();
    const users            = localStorage.getItem(Tokens.USER);
    const firstName        = localStorage.getItem(Tokens.FIRST_NAME);
    const lastName         = localStorage.getItem(Tokens.LAST_NAME);
    const roleName         = localStorage.getItem(Tokens.ROLE_NAME);
    const token            = localStorage.getItem(Tokens.ADMIN);
    const imageUrl         = localStorage.getItem(Tokens.USER_IMAGE_URL);
    const image             = localStorage.getItem(Tokens.IMAGE);
    const updatedEmail     = localStorage.getItem(Tokens.UPDATED_EMAIL);
    const updatedFirstName = localStorage.getItem(Tokens.UPDATED_FIRST_NAME);
    const updatedLastName  = localStorage.getItem(Tokens.UPDATED_LAST_NAME);
    const currentLanguageIso = localStorage.getItem(Tokens.UPDATED_LANGUAGE);

    const [deleteModel,          setDeleteModel]          = useState(false);
    const [isFullscreen,         setIsFullscreen]         = useState(false);
    const [showPosRegisterModel, setShowPosRegisterModel] = useState(false);

    const { allConfigData, languages } = useSelector(state => state);
    const { stores, currentStoreId } = useSelector(state => state.myStores);
    const dispatch = useDispatch();

    // Sin 2+ tiendas no hay nada que elegir -- no se agrega fricción
    // visual nueva a la instalación single-store de hoy.
    const onSelectStore = (store) => {
        if (!store.is_active || String(store.id) === String(currentStoreId)) return;
        dispatch(setCurrentStore(store.id));
        window.location.reload();
    };
    const currentStoreName = stores.find((s) => String(s.id) === String(currentStoreId))?.name;
    const subscription = allConfigData?.subscription;
    const isTrial = subscription?.plan?.code === 'trial';
    const trialExpired = subscription?.status === 'EXPIRED';

    // El dropdown de idioma necesita la lista completa (antes solo la
    // pedía LanguageModel, que ahora se reemplaza por este dropdown
    // directo -- sin modal de por medio).
    useEffect(() => {
        dispatch(fetchAllLanguage());
    }, []);

    const currentLanguage = languages.find(
        (lang) => String(lang.attributes?.iso_code) === String(currentLanguageIso)
    );

    const onSelectLanguage = (lang) => {
        if (String(lang.attributes.iso_code) === String(currentLanguageIso)) return;
        updateLanguage({ language: lang.attributes.iso_code }, lang.id);
    };

    const fullName  = (updatedFirstName && updatedLastName)
        ? `${updatedFirstName} ${updatedLastName}`
        : `${firstName} ${lastName}`;
    const avatarSrc = imageUrl || image || null;

    const onLogOut       = () => { logoutAction(token, navigate); navigate('/login'); };
    const onProfileClick = () => { window.location.href = '#/app/profile/edit'; };
    const fullScreen     = () => {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
            setIsFullscreen(true);
        } else {
            document.exitFullscreen();
            setIsFullscreen(false);
        }
    };

    const hasPosPermission = newRoutes
        .map(r => r.permission)
        .some(p => p === 'manage_pos_screen');

    return (
        <>
            <style>{headerStyles}</style>

            <Navbar collapseOnSelect expand='lg' className='hdr-navbar align-items-center ms-auto py-0'>

                {isTrial && (
                    <div
                        className={`hdr-trial-badge${trialExpired ? ' hdr-trial-badge--expired' : ''}`}
                        title={trialExpired ? 'El periodo de prueba terminó' : `Prueba válida hasta ${subscription.trial_ends_at || ''}`}
                    >
                        <FontAwesomeIcon icon={faClock} />
                        <span className='d-none d-md-inline'>{trialExpired ? 'Prueba finalizada' : 'Prueba'}</span>
                        <b>{trialExpired ? '!' : `${subscription.days_remaining}d`}</b>
                    </div>
                )}

                {/* POS */}
                {hasPosPermission && (
                    <div className='me-2'>
                        {allConfigData?.open_register === true ? (
                            <button onClick={() => setShowPosRegisterModel(true)} className='hdr-pos-btn'>
                                {getFormattedMessage('header.pos.title')}
                            </button>
                        ) : (
                            <Link to='/app/pos' className='hdr-pos-btn'>
                                {getFormattedMessage('header.pos.title')}
                            </Link>
                        )}
                    </div>
                )}

                {/* Selector de tienda -- solo visible con 2+ tiendas */}
                {stores.length > 1 && (
                    <Dropdown align='end'>
                        <Dropdown.Toggle as='div' className='hdr-store-btn hide-arrow' id='hdr-store-dropdown'>
                            <FontAwesomeIcon icon={faStore} className='hdr-store-icon' />
                            <span className='hdr-store-name d-none d-sm-block'>{currentStoreName || getFormattedMessage('header.store-menu.select.label')}</span>
                            <FontAwesomeIcon icon={faAngleDown} className='hdr-store-chevron d-none d-sm-block' />
                        </Dropdown.Toggle>
                        <Dropdown.Menu className='hdr-dropdown-menu'>
                            {stores.map((store) => (
                                <Dropdown.Item
                                    key={store.id}
                                    onClick={() => onSelectStore(store)}
                                    className={`hdr-dropdown-item${!store.is_active ? ' hdr-dropdown-item--disabled' : ''}`}
                                    disabled={!store.is_active}
                                >
                                    <div className='hdr-item-icon'>
                                        {String(store.id) === String(currentStoreId) && <FontAwesomeIcon icon={faCheck} />}
                                    </div>
                                    <span>{store.name}</span>
                                    {!store.is_active && (
                                        <span className='hdr-store-inactive-badge'>
                                            {getFormattedMessage('header.store-menu.inactive.label')}
                                        </span>
                                    )}
                                </Dropdown.Item>
                            ))}
                        </Dropdown.Menu>
                    </Dropdown>
                )}

                {/* Selector de idioma -- antes vivía como un modal disparado
                    desde el menú del avatar, ahora es un dropdown directo
                    igual que el de tienda (selección instantánea, sin
                    modal de por medio). */}
                {languages.length > 0 && (
                    <Dropdown align='end'>
                        <Dropdown.Toggle as='div' className='hdr-store-btn hide-arrow' id='hdr-language-dropdown'>
                            <FontAwesomeIcon icon={faGlobe} className='hdr-store-icon' />
                            <span className='hdr-store-name d-none d-sm-block'>{currentLanguage?.attributes?.name || getFormattedMessage('header.language-menu.select.label')}</span>
                            <FontAwesomeIcon icon={faAngleDown} className='hdr-store-chevron d-none d-sm-block' />
                        </Dropdown.Toggle>
                        <Dropdown.Menu className='hdr-dropdown-menu'>
                            {languages.map((lang) => (
                                <Dropdown.Item
                                    key={lang.id}
                                    onClick={() => onSelectLanguage(lang)}
                                    className='hdr-dropdown-item'
                                >
                                    <div className='hdr-item-icon'>
                                        {String(lang.attributes?.iso_code) === String(currentLanguageIso) && <FontAwesomeIcon icon={faCheck} />}
                                    </div>
                                    <span>{lang.attributes?.name}</span>
                                </Dropdown.Item>
                            ))}
                        </Dropdown.Menu>
                    </Dropdown>
                )}

                {/* Fullscreen */}
                <div className='hdr-icon-btn' onClick={fullScreen} title={isFullscreen ? 'Salir pantalla completa' : 'Pantalla completa'}>
                    <FontAwesomeIcon icon={isFullscreen ? faMinimize : faMaximize} style={{ fontSize: 14 }} />
                </div>

                {/* User dropdown */}
                <Dropdown align='end'>
                    <Dropdown.Toggle as='div' className='hdr-user-trigger hide-arrow' id='hdr-user-dropdown'>
                        {avatarSrc ? (
                            <img src={avatarSrc} className='hdr-avatar-img' alt='avatar' />
                        ) : (
                            <div className='hdr-avatar-initials'>{getAvatarName(fullName)}</div>
                        )}
                        <span className='hdr-user-name d-none d-sm-block'>
                            <span className='hdr-user-name-value'>{fullName}</span>
                            {roleName && <span className='hdr-user-role'>{roleName}</span>}
                        </span>
                        <FontAwesomeIcon icon={faAngleDown} className='hdr-chevron d-none d-sm-block' />
                    </Dropdown.Toggle>

                    <Dropdown.Menu className='hdr-dropdown-menu'>
                        {/* Info usuario */}
                        <div className='hdr-dropdown-header'>
                            {avatarSrc ? (
                                <img src={avatarSrc} className='hdr-dropdown-avatar' alt='avatar' />
                            ) : (
                                <div className='hdr-dropdown-initials'>{getAvatarName(fullName)}</div>
                            )}
                            <div className='hdr-dropdown-name'>{fullName}</div>
                            <div className='hdr-dropdown-email'>{updatedEmail || users}</div>
                        </div>

                        <Dropdown.Item onClick={onProfileClick} className='hdr-dropdown-item'>
                            <div className='hdr-item-icon'><FontAwesomeIcon icon={faUser} /></div>
                            {getFormattedMessage('header.profile-menu.profile.label')}
                        </Dropdown.Item>

                        <Dropdown.Item onClick={() => setDeleteModel(true)} className='hdr-dropdown-item'>
                            <div className='hdr-item-icon'><FontAwesomeIcon icon={faLock} /></div>
                            {getFormattedMessage('header.profile-menu.change-password.label')}
                        </Dropdown.Item>

                        <div className='hdr-divider' />

                        <Dropdown.Item onClick={onLogOut} className='hdr-dropdown-item hdr-dropdown-item--logout'>
                            <div className='hdr-item-icon'><FontAwesomeIcon icon={faRightFromBracket} /></div>
                            {getFormattedMessage('header.profile-menu.logout.label')}
                        </Dropdown.Item>
                    </Dropdown.Menu>
                </Dropdown>
            </Navbar>

            {deleteModel && (
                <ChangePassword deleteModel={deleteModel} onClickDeleteModel={() => setDeleteModel(false)} />
            )}
            <PosRegisterModel
                showPosRegisterModel={showPosRegisterModel}
                onClickshowPosRegisterModel={() => setShowPosRegisterModel(false)}
            />
        </>
    );
};

const mapStateToProps = (state) => {
    const { selectedLanguage } = state;
    return { selectedLanguage };
};

export default connect(mapStateToProps, { logoutAction, updateLanguage })(Header);
