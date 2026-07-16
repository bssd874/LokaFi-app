#![cfg(test)]

use super::*;
use soroban_sdk::{
    testutils::{Address as _, MockAuth, MockAuthInvoke},
    Address, BytesN, Env, IntoVal, String,
};

fn hash(env: &Env, byte: u8) -> BytesN<32> {
    BytesN::from_array(env, &[byte; 32])
}

fn setup() -> (Env, Address, Address, Address) {
    let env = Env::default();
    env.mock_all_auths();
    let contract_id = env.register(LokaFiInvoiceRegistry, ());
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);
    let admin = Address::generate(&env);
    let merchant = Address::generate(&env);
    client.initialize(&admin);

    (env, contract_id, admin, merchant)
}

fn register(
    env: &Env,
    client: &LokaFiInvoiceRegistryClient<'_>,
    merchant: &Address,
    invoice_byte: u8,
) {
    client.register_invoice(
        &hash(env, invoice_byte),
        merchant,
        &10_000_000,
        &hash(env, 90),
    );
}

#[test]
fn successful_initialization() {
    let env = Env::default();
    env.mock_all_auths();
    let contract_id = env.register(LokaFiInvoiceRegistry, ());
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);
    let admin = Address::generate(&env);

    client.initialize(&admin);

    assert_eq!(
        client.version(),
        String::from_str(&env, "lokafi_invoice_registry_v1")
    );
}

#[test]
fn initialization_cannot_happen_twice() {
    let (env, contract_id, admin, _) = setup();
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);

    assert_eq!(
        client.try_initialize(&admin),
        Err(Ok(ContractError::AlreadyInitialized.into()))
    );
    assert_eq!(client.invoice_exists(&hash(&env, 1)), false);
}

#[test]
fn successful_invoice_registration_and_retrieval() {
    let (env, contract_id, _, merchant) = setup();
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);
    let invoice_hash = hash(&env, 1);
    let memo_hash = hash(&env, 90);

    client.register_invoice(&invoice_hash, &merchant, &10_000_000, &memo_hash);
    let record = client.get_invoice(&invoice_hash).unwrap();

    assert_eq!(record.invoice_hash, invoice_hash);
    assert_eq!(record.merchant, merchant);
    assert_eq!(record.amount, 10_000_000);
    assert_eq!(record.memo_hash, memo_hash);
    assert_eq!(record.status, InvoiceStatus::Pending);
    assert_eq!(record.transaction_hash, None);
    assert_eq!(client.invoice_exists(&hash(&env, 1)), true);
    assert_eq!(client.invoice_exists(&hash(&env, 2)), false);
}

#[test]
fn duplicate_invoice_is_rejected() {
    let (env, contract_id, _, merchant) = setup();
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);
    register(&env, &client, &merchant, 1);

    assert_eq!(
        client.try_register_invoice(&hash(&env, 1), &merchant, &10_000_000, &hash(&env, 91)),
        Err(Ok(ContractError::InvoiceAlreadyExists.into()))
    );
}

#[test]
fn invalid_amount_is_rejected() {
    let (env, contract_id, _, merchant) = setup();
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);

    assert_eq!(
        client.try_register_invoice(&hash(&env, 1), &merchant, &0, &hash(&env, 90)),
        Err(Ok(ContractError::InvalidAmount.into()))
    );
}

#[test]
fn successful_paid_state_transition() {
    let (env, contract_id, _, merchant) = setup();
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);
    let payer = Address::generate(&env);
    let invoice_hash = hash(&env, 1);
    let transaction_hash = hash(&env, 2);
    register(&env, &client, &merchant, 1);

    client.mark_invoice_paid(&invoice_hash, &transaction_hash, &Some(payer.clone()));
    let record = client.get_invoice(&invoice_hash).unwrap();

    assert_eq!(record.status, InvoiceStatus::Paid);
    assert_eq!(record.transaction_hash, Some(transaction_hash.clone()));
    assert_eq!(record.payer, Some(payer));
    assert_eq!(client.is_transaction_used(&transaction_hash), true);
    assert_eq!(client.is_transaction_used(&hash(&env, 3)), false);
}

#[test]
fn unknown_invoice_is_rejected() {
    let (env, contract_id, _, _) = setup();
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);

    assert_eq!(
        client.try_mark_invoice_paid(&hash(&env, 1), &hash(&env, 2), &None),
        Err(Ok(ContractError::InvoiceNotFound.into()))
    );
}

#[test]
fn already_paid_invoice_is_rejected() {
    let (env, contract_id, _, merchant) = setup();
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);
    register(&env, &client, &merchant, 1);
    client.mark_invoice_paid(&hash(&env, 1), &hash(&env, 2), &None);

    assert_eq!(
        client.try_mark_invoice_paid(&hash(&env, 1), &hash(&env, 3), &None),
        Err(Ok(ContractError::InvoiceAlreadyPaid.into()))
    );
}

#[test]
fn reused_transaction_hash_is_rejected() {
    let (env, contract_id, _, merchant) = setup();
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);
    register(&env, &client, &merchant, 1);
    register(&env, &client, &merchant, 2);
    let transaction_hash = hash(&env, 3);
    client.mark_invoice_paid(&hash(&env, 1), &transaction_hash, &None);

    assert_eq!(
        client.try_mark_invoice_paid(&hash(&env, 2), &transaction_hash, &None),
        Err(Ok(ContractError::TransactionAlreadyUsed.into()))
    );
}

#[test]
#[should_panic]
fn unauthorized_state_change_is_rejected() {
    let env = Env::default();
    let contract_id = env.register(LokaFiInvoiceRegistry, ());
    let client = LokaFiInvoiceRegistryClient::new(&env, &contract_id);
    let admin = Address::generate(&env);
    let merchant = Address::generate(&env);

    client
        .mock_auths(&[MockAuth {
            address: &admin,
            invoke: &MockAuthInvoke {
                contract: &contract_id,
                fn_name: "initialize",
                args: (&admin,).into_val(&env),
                sub_invokes: &[],
            },
        }])
        .initialize(&admin);

    client.register_invoice(&hash(&env, 1), &merchant, &10_000_000, &hash(&env, 90));
}
