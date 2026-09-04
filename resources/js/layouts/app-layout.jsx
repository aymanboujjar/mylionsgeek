import FullPageLoader from '@/components/full-page-loader';
import ShowSkippableModal from '@/components/ShowSkippableModal';
import { useImagesLoaded } from '@/hooks/use-images-loaded';
import AppHeaderLayout from '@/layouts/app/app-header-layout';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { usePage } from '@inertiajs/react';

/**
 * AppLayout — the single layout used by every page.
 *
 * @param {React.ReactNode} children - Page content
 * @param {Array}  breadcrumbs       - Optional breadcrumb items forwarded to AppSidebarLayout's
 *                                     header. Defaults to [] inside AppSidebarLayout so it is
 *                                     never required here.
 */

/** Student-style top nav routes (same links as `AppHeader`): hide admin sidebar for staff here. */
function isStudentPublicShellPath(pathname) {
    if (pathname === '/students/feed') {
        return true;
    }
    const prefixes = [
        '/students/jobs',
        '/students/leaderboard',
        '/students/spaces',
        '/students/reservations',
        '/students/attendance',
        '/students/projects',
        '/students/project',
    ];

    if (prefixes.some((p) => pathname === p || pathname.startsWith(`${p}/`))) {
        return true;
    }

    // Student profile & posts: /students/123, /students/123/posts
    if (/^\/students\/\d+$/.test(pathname) || /^\/students\/\d+\/posts$/.test(pathname)) {
        return true;
    }

    return false;
}

export default function AppLayout({ children, breadcrumbs, ...props }) {
    const page = usePage();
    const { auth } = page.props;

    // Always treat roles as an array
    const userRoles = Array.isArray(auth?.user?.role) ? auth.user.role : [auth?.user?.role || ''];

    const isRecruiter = userRoles.includes('recruiter');
    const isStaff = userRoles.some((role) => ['admin', 'moderateur', 'studio_responsable', 'coach', 'super_admin', 'pro'].includes(role));

    // Student-area pages: top navbar only (no admin sidebar) so staff browse like students.
    const pathname = page.url.split('?')[0];
    const useStudentHeaderShell = isStaff && isStudentPublicShellPath(pathname);
    const useSidebarLayout = (isRecruiter || isStaff) && !useStudentHeaderShell;

    const Layout = useSidebarLayout ? AppSidebarLayout : AppHeaderLayout;

    const needsStudentHeaderOffset = !useSidebarLayout && userRoles.some((r) => ['student', 'coworker'].includes(r));

    const { areImagesLoaded } = useImagesLoaded({ key: page.url });

    return (
        <>
            <FullPageLoader isVisible={!areImagesLoaded} />
            <Layout breadcrumbs={breadcrumbs} {...props}>
                <div
                    className={`bg-light dark:bg-dark ${needsStudentHeaderOffset ? 'pt-20' : ''} mx-auto my-6 h-full w-[96%] rounded-lg shadow-lg`}
                >
                    <ShowSkippableModal />
                    {children}
                </div>
            </Layout>
        </>
    );
}
