import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useInitials } from '@/hooks/use-initials';
import RoleBadge from '@/pages/admin/users/partials/RoleBadge';
import Rolegard from '@/components/rolegard';
import { genderLabel, programStatusLabel } from '@/components/helpers/userDemographics';
import { router, usePage } from '@inertiajs/react';
import { CameraIcon, ChevronsLeft, ChevronsRight, CircleCheckBig, Pencil, Trash, UsersRoundIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import EditModal from './EditModal';
import User from './ShowModal';

const UsersTable = ({ users, filters, roles = [], trainings = [], status }) => {
    const { auth } = usePage().props;
    const [currentPage, setCurrentPage] = useState(1);
    const [filterUsers, setFilterUsers] = useState(users);
    const [openEditUser, setOpenEditUser] = useState(null);
    const [openShow, setOpenShow] = useState(false);
    const [showedUser, setShowedUser] = useState(null);
    const [deleteUser, setDeleteUser] = useState(null); // Holds the user to be deleted
    const [openDelete, setOpenDelete] = useState(false); // Controls the delete dialog visibility
    const getInitials = useInitials();
    const itemsPerPage = 10;
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const currentItems = filterUsers.slice(startIndex, endIndex);
    const totalPages = Math.ceil(filterUsers.length / itemsPerPage);

    useEffect(() => {
        setFilterUsers(users);
        setCurrentPage(1);
    }, [users]);

    const onShow = (user) => {
        setShowedUser(user);
        setOpenShow(true);
    };

    // Change account status or delete user logic
    const changeAccountStatus = (user) => {
        setDeleteUser(user); // Set the user to be deleted
        setOpenDelete(true); // Open the delete confirmation dialog
    };

    // Actually delete the user
    const deleteConfirmedUser = () => {
        if (deleteUser) {
            // Assuming the delete endpoint is something like this
            const newState = deleteUser.account_state === 1 ? 0 : 1;
            router.post(
                `/admin/users/update/${deleteUser.id}/account-state`,
                {
                    _method: 'put',
                    account_state: newState,
                },
                {
                    onSuccess: () => {
                        // Handle success
                        setOpenDelete(false); // Close the delete dialog
                        setDeleteUser(null); // Clear the delete user state
                    },
                    onError: () => {
                        // Handle error
                        setOpenDelete(false); // Close the delete dialog in case of error
                        setDeleteUser(null); // Clear the delete user state
                    },
                },
            );
        }
    };

    // //('All users:', currentItems);
    // //('First user roles:', currentItems[0]?.roles);
    return (
        <div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-[100px]">Members</TableHead>
                        <TableHead>Access</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Gender</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Role</TableHead>
                        <Rolegard authorized={['admin', 'super_admin']}>
                            <TableHead>Menu</TableHead>
                        </Rolegard>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {currentItems.map((user) => (
                        <TableRow key={user.id} className="cursor-pointer">
                            <TableCell className="flex items-center gap-4 font-medium" onClick={() => onShow(user)}>
                                <Avatar
                                    className="h-8 w-8 overflow-hidden rounded-full"
                                    image={user.image}
                                    name={user.name}
                                    lastActivity={user.last_login ?? user.last_online ?? user.last_activity ?? null}
                                    onlineCircleClass="hidden"
                                />

                                <div className="flex flex-col">
                                    <h1 className="capitalize">{user.name}</h1>
                                    {(() => {
                                        const userRoles = Array.isArray(auth.user.role) ? auth.user.role : [auth.user.role];
                                        if (userRoles.includes('admin')) {
                                            return <span className="text-[0.8rem] font-medium text-dark/80 dark:text-light/80">{user.cin}</span>;
                                        }
                                        return null;
                                    })()}
                                </div>
                            </TableCell>
                            <TableCell className="font-medium">
                                <div className="flex items-center gap-3">
                                    {user.access_cowork === 1 && (
                                        <UsersRoundIcon size={20} className="cursor-pointer text-alpha" aria-label="Coworking Access" />
                                    )}
                                    {user.access_studio === 1 && (
                                        <CameraIcon size={20} className="cursor-pointer text-alpha" aria-label="Studio Access" />
                                    )}
                                    {user.access_studio === 0 && user.access_cowork === 0 && (
                                        <span className="text-sm text-dark dark:text-light">No Access</span>
                                    )}
                                </div>
                            </TableCell>
                            <TableCell className="font-medium">{user.email}</TableCell>
                            <TableCell className="font-medium">{genderLabel(user.gender) || '—'}</TableCell>
                            <TableCell className="font-medium">{programStatusLabel(user.program_status) || '—'}</TableCell>
                            <TableCell>
                                <div className="flex flex-wrap gap-2">
                                    {user.role ? (
                                        <RoleBadge role={user.role} email={user.email} />
                                    ) : (
                                        <span className="text-xs text-gray-400">No role</span>
                                    )}
                                </div>
                            </TableCell>

                            <Rolegard authorized={['admin', 'super_admin']}>
                                <TableCell className="flex items-center gap-2 font-medium">
                                    <Button
                                        className="bg-transparent p-2 duration-200 hover:bg-transparent"
                                        title="Edit"
                                        onClick={() => setOpenEditUser(user)}
                                    >
                                        <Pencil size={20} className="text-alpha" />
                                    </Button>
                                    <Button
                                        className="cursor-pointer bg-transparent p-2 duration-200 hover:bg-transparent"
                                        title={user.account_state ? 'Active' : 'Suspend'}
                                        onClick={() => changeAccountStatus(user)} // Open delete confirmation dialog
                                    >
                                        {user.account_state ? (
                                            <CircleCheckBig size={25} className="text-green-600" />
                                        ) : (
                                            <Trash size={25} className="text-error" />
                                        )}
                                    </Button>
                                </TableCell>
                            </Rolegard>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            {/* deleteModal */}
            {openDelete && (
                <AlertDialog open={openDelete}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle className="text-dark dark:text-light">Are you absolutely sure?</AlertDialogTitle>
                            <AlertDialogDescription className="text-dark dark:text-light">
                                {deleteUser.account_state
                                    ? 'This will reactivate this account and restore access to this account data.'
                                    : 'This will temporarily suspend this account and prevent access to this account data until reactivated.'}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel className="cursor-pointer" onClick={() => setOpenDelete(false)}>
                                Cancel
                            </AlertDialogCancel>
                            <AlertDialogAction
                                className={`cursor-pointer ${deleteUser.account_state ? 'bg-alpha text-black hover:bg-alpha/80' : 'bg-error text-black hover:bg-error/80'}`}
                                onClick={deleteConfirmedUser}
                            >
                                Continue
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}

            {/* edit modal */}
            <EditModal
                open={!!openEditUser}
                onClose={() => setOpenEditUser(null)}
                editedUser={openEditUser}
                roles={roles}
                status={status}
                trainings={trainings}
            />

            {/* Show User Dialog */}
            {openShow && <User open={openShow} close={() => setOpenShow(false)} user={showedUser} trainings={trainings} />}

            {/* Pagination */}
            <div className="mt-10 flex w-full items-center justify-center gap-5">
                <button
                    disabled={currentPage === 1}
                    onClick={() => setCurrentPage((prev) => prev - 1)}
                    className="cursor-pointer rounded-lg bg-beta p-2 text-light dark:bg-light dark:text-dark"
                >
                    <ChevronsLeft />
                </button>

                <span>
                    Page {currentPage} of {totalPages}
                </span>

                <button
                    disabled={currentPage === totalPages}
                    onClick={() => setCurrentPage((prev) => prev + 1)}
                    className="cursor-pointer rounded-lg bg-beta p-2 text-light dark:bg-light dark:text-dark"
                >
                    <ChevronsRight />
                </button>
            </div>
        </div>
    );
};

export default UsersTable;
