<?php

namespace App\Controllers;

use App\Services\ReservationDocumentService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
use DomainException;
use Throwable;

class ReservationDocumentController extends BaseController
{
    public function borrowerDownload(int $reservationId, int $documentId)
    {
        try {
            $file = (new ReservationDocumentService())->downloadForBorrower(
                (int) session()->get('user_id'),
                $reservationId,
                $documentId
            );
            return $this->download($file);
        } catch (DomainException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'A private reservation form could not be verified.');
            return $this->response->setStatusCode(503)->setBody('The reservation form is temporarily unavailable.');
        }
    }

    public function adminDownload(int $reservationId, int $documentId)
    {
        try {
            $file = (new ReservationDocumentService())->downloadForAdmin(
                (int) session()->get('user_id'),
                $reservationId,
                $documentId
            );
            return $this->download($file);
        } catch (DomainException $exception) {
            throw PageNotFoundException::forPageNotFound($exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'A private reservation form could not be verified.');
            return $this->response->setStatusCode(503)->setBody('The reservation form is temporarily unavailable.');
        }
    }

    public function resubmit(int $reservationId)
    {
        $upload = $this->request->getFile('request_form');
        try {
            (new ReservationDocumentService())->resubmit(
                (int) session()->get('user_id'),
                $reservationId,
                $upload instanceof UploadedFile ? $upload : null
            );
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('success', 'Form submitted for administrator review.');
        } catch (DomainException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'A replacement reservation form could not be recorded.');
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('error', 'The replacement form could not be recorded. Please retry.');
        }
    }

    public function review(int $reservationId, int $documentId)
    {
        $decision = $this->request->getPost('decision');
        $reason = $this->request->getPost('rejection_reason');
        if (!is_string($decision) || ($reason !== null && !is_string($reason))) {
            return redirect()->to(site_url('admin/reservations/' . $reservationId))
                ->with('error', 'Enter a valid form review decision.');
        }

        try {
            (new ReservationDocumentService())->review(
                (int) session()->get('user_id'),
                $reservationId,
                $documentId,
                $decision,
                $reason ?? ''
            );
            return redirect()->to(site_url('admin/reservations/' . $reservationId))
                ->with('success', 'Reservation form review recorded.');
        } catch (DomainException $exception) {
            return redirect()->to(site_url('admin/reservations/' . $reservationId))->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'A reservation form review could not be recorded.');
            return redirect()->to(site_url('admin/reservations/' . $reservationId))
                ->with('error', 'The form review could not be recorded. Please retry.');
        }
    }

    private function download(array $file)
    {
        $contents = file_get_contents($file['path']);
        if (!is_string($contents)) {
            return $this->response->setStatusCode(503)->setBody('The reservation form is temporarily unavailable.');
        }
        $filename = 'reservation-form.' . $file['extension'];

        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', $file['mime_type'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setBody($contents);
    }
}
