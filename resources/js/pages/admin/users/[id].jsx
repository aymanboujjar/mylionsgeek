import AppLayout from '@/layouts/app-layout';
import AttendanceTab from './partials/components/ActivityTab';
import PostsTab from './partials/components/PostsTab';
import ProfileHeader from './partials/components/ProfileHeader';
import ProfileMainContent from './partials/components/ProfileMainContent';
import ProfileSidebar from './partials/components/ProfileSideBar';
import ProfileStatsGrid from './partials/components/ProfileState';
import ProjectsTab from './partials/components/ProjectsTab';
import ReservationsTab from './partials/components/ReservationsTab';
import TrainingTab from './partials/components/TrainingTab';

export default function AdminUserProfile({
    user,
    assignedComputer,
    userProjects,
    collaborativeProjects,
    posts,
    reservations,
    trainings,
    absences,
    discipline,
    roles,
    canEnrollFace = false,
    faceEnrollment = null,
}) {
    //console.log(posts);

    return (
        <AppLayout>
            <div className="min-h-screen bg-neutral-50 dark:bg-neutral-950">
                <ProfileHeader user={user} trainings={trainings} roles={roles} />
                <div className="bg-neutral-50 dark:bg-dark">
                    <div className="mx-auto max-w-7xl px-6">
                        <ProfileStatsGrid user={user} />
                        <ProfileMainContent
                            sidebar={
                                <ProfileSidebar
                                    user={user}
                                    assignedComputer={assignedComputer}
                                    canEnrollFace={canEnrollFace}
                                    faceEnrollment={faceEnrollment}
                                />
                            }
                            tabs={{
                                posts: <PostsTab posts={posts} user={user} />,
                                attendance: <AttendanceTab absences={absences} discipline={discipline} />,
                                projects: <ProjectsTab userProjects={userProjects} collaborativeProjects={collaborativeProjects} />,
                                reservations: <ReservationsTab reservations={reservations} />,
                                training: <TrainingTab trainings={trainings} />,
                            }}
                        />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
