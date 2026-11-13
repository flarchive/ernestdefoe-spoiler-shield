import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-spoiler-shield.admin.${key}`, params, true);

const blurOrShow = () => ({ blur: t('settings.blur'), show: t('settings.show') });

export default [
  new Extend.Admin()
    .setting(
      () => ({
        setting: 'ernestdefoe-spoiler-shield.members',
        type: 'select',
        options: blurOrShow(),
        default: 'blur',
        label: t('settings.members'),
        help: t('settings.members_help'),
      }),
      100
    )
    .setting(
      () => ({
        setting: 'ernestdefoe-spoiler-shield.guests',
        type: 'select',
        options: blurOrShow(),
        default: 'blur',
        label: t('settings.guests'),
        help: t('settings.guests_help'),
      }),
      95
    )
    .setting(
      () => ({
        setting: 'ernestdefoe-spoiler-shield.stamp_replies',
        type: 'boolean',
        label: t('settings.stamp_replies'),
        help: t('settings.stamp_replies_help'),
      }),
      90
    )
    .permission(() => ({ icon: 'fas fa-book-open', label: t('permissions.create_works'), permission: 'spoiler-shield.createWorks' }), 'start', 80)
    .permission(
      () => ({ icon: 'fas fa-user-secret', label: t('permissions.manage_works'), permission: 'spoiler-shield.manageWorks' }),
      'moderate',
      80
    ),
];
